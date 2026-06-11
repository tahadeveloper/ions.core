<?php

declare(strict_types=1);

namespace Ions\Foundation;

use App\Booting;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Ions\Bundles\AttributeRouteControllerLoader;
use Ions\Bundles\Path;
use Ions\Container\Container;
use Ions\Events\RequestHandled;
use Ions\Http\ActionArgumentResolver;
use Ions\Http\ExceptionHandler;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Http\Middleware\Pipeline;
use Ions\Http\ResponseNormalizer;
use Ions\Security\Jwt;
use Ions\Security\SecurityHeaders;
use Ions\Support\Arr;
use Ions\Support\Request;
use Ions\Support\Response;
use Ions\Support\Session;
use Ions\Support\Storage;
use Ions\Support\Str;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\NoConfigurationException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

class Kernel extends Singleton
{
    protected static string $environmentPath;
    protected static ?Session $session = null;
    protected static ?Request $request = null;
    protected static ?Response $response = null;
    protected static Config|array $config = [];
    protected static Container $app;
    protected static RouteCollection $collection;

    /**
     * Per-group (web/api) route collections, captured once per process.
     *
     * @var array<string, RouteCollection>
     */
    protected static array $routeCollections = [];

    /**
     * Per-group compiled route caches loaded from var/cache/routes/{group}.php.
     * Value is the compiled-routes array, or false when no cache file applies.
     *
     * @var array<string, array|false>
     */
    protected static array $compiledRoutes = [];

    public static string $envName = '.env';

    /**
     * Armed (permanently, for the life of the process) by resetForTesting().
     * When true, failBoot() rethrows the boot error instead of die()-ing so a
     * test runner reports the failure instead of being killed mid-suite.
     */
    private static bool $testing = false;

    /**
     * boot app with evn properties.
     *
     * @param string|null $basePath Optional absolute path to the host-app root.
     *                              When provided, Path::setBasePath() and
     *                              static::$environmentPath are set to that value so
     *                              the Kernel can be booted against any directory
     *                              (e.g. a test fixture). When omitted the existing
     *                              5-levels-up realpath resolution is used unchanged.
     * @return void
     */
    public static function boot(?string $basePath = null): void
    {
        try {
            if ($basePath !== null) {
                Path::setBasePath($basePath);
                static::$environmentPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            } else {
                \Ions\Bundles\Path::resetBasePath();
                static::$environmentPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR;
            }

            self::structureBone();

            (Dotenv::createImmutable(realpath(static::$environmentPath), static::$envName))->safeLoad();

            self::Container();
            self::captureConfig();

            static::$collection = new RouteCollection();
            static::$routeCollections = [];
            static::$compiledRoutes = [];

            include_once Path::core('helpers.php');

            $trustedHosts = config('app.trusted_hosts', []);
            if (!empty($trustedHosts)) {
                Request::setTrustedHosts($trustedHosts);
            }

            self::applyTrustedProxies();

            self::bootProviders();

        } catch (Throwable $e) {
            self::failBoot($e, 'Booting ions failed');
        }

        if (class_exists(Booting::class)) {
            Booting::boot();
        }

        date_default_timezone_set(env('TIME_ZONE', 'Africa/Cairo'));

        self::preloads();
    }

    /**
     * Reset cached static state so the kernel can be re-booted cleanly.
     * Intended for test isolation only.
     */
    public static function resetForTesting(): void
    {
        self::$testing = true;
        static::$config = [];
        static::$session = null;
        static::$request = null;
        static::$response = null;
        static::$routeCollections = [];
        static::$compiledRoutes = [];
        // boot() (and TrustedHostMiddleware) set trusted-host patterns on a
        // Symfony Request CLASS static — clear it or every later boot in this
        // process inherits the previous app's host allowlist.
        Request::setTrustedHosts([]);
        // Same hygiene for trusted proxies (boot()/handle() set them from
        // config('app.trusted_proxies')): an empty list disables proxy trust;
        // the header-set argument is required by Symfony but inert without
        // proxies.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        \Ions\Bundles\Path::resetBasePath();
        // Route facade statics (prefix/name/middleware stacks, deferred
        // fallback) — a test that aborts mid-group must not leak into the
        // next boot.
        \Ions\Bundles\Route::resetForTesting();
        Discovery::reset();
        // ORM strict mode (10.6) flips Eloquent CLASS statics; restore the
        // library defaults so a strict-mode test can never pollute the next
        // boot (DatabaseProvider::boot() re-computes them anyway).
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(false);
    }

    /**
     * Whether boot() has completed far enough that the container exists.
     */
    public static function isBooted(): bool
    {
        return isset(static::$app);
    }

    /**
     * Reset PER-REQUEST state so the same booted process can safely handle the
     * next request (worker mode: FrankenPHP/RoadRunner/Swoole, or sequential
     * Kernel::handle() calls in one process).
     *
     * Cleared (per-request):
     *   - the shared Request / Response / legacy Session statics (rebuilt fresh),
     *   - the framework session: SessionManager::renew() swaps in a brand-new
     *     inner Symfony session, and the request on the shared 'request_stack'
     *     is re-pointed at it — the CSRF manager reads through that stack, so
     *     its token storage follows automatically,
     *   - the per-request Twig globals (_csrf_token, _trans, appUrl) on the
     *     shared 'view.env' Environment (only when already built),
     *   - the Eloquent query log, when config('database.query_log') is enabled
     *     (otherwise it would accumulate unbounded across worker requests),
     *   - the log correlation id (RequestIdProcessor::reset()) so every
     *     channel stamps the next request with a fresh extra.request_id.
     *
     * Kept (boot state):
     *   - the Config object and the container with ALL its singletons
     *     (cache/db/jwt/session manager binding/view.env/…),
     *   - the 8.1 per-group route memo and compiled route caches,
     *   - the Twig Environment object itself (globals refreshed, not rebuilt).
     *
     * Subsystems added in 8.x–12.x that are isolated WITHOUT extra reset work
     * here. The per-request-stateful ones (Gate, Flash, trusted proxies,
     * response cache, IonDisk overrides) are proven isolated by the 12.6 leak
     * matrix (tests/Feature/Runtime/WorkerLeakMatrixTest.php); the boot-state
     * ones (Scheduler, ORM-strict) are kept by design with nothing to assert:
     *   - Gate (10.4): the 'gate' singleton resolves the user lazily from
     *     Kernel::request()->attributes['auth_user'] on every check and never
     *     caches it; clearing the request static (re-pointed by handle()) makes
     *     the next request a guest. forUser() scope lives on a clone, not the
     *     singleton.
     *   - Flash (10.3): consume memo lives on the per-request attribute bag
     *     (dies with the request); the FlashBag is part of the inner session,
     *     which renew() above replaces, so un-consumed flash never bleeds.
     *   - Trusted proxies/hosts (10.1/8.4): handle() re-applies
     *     applyTrustedProxies() against THIS request, and isSecure()/clientIp()
     *     read the request's own headers — no cross-request bleed.
     *   - Response cache (12.5): stateless middleware/ResponseCache instances
     *     keyed by request; distinct URLs get distinct cache keys.
     *   - IonDisk per-call overrides (12.1): applied and restored in a finally
     *     within each call, so the static bucket/basePath never leak.
     *   - Scheduler (9.4) / ORM-strict flags (10.6): boot-time state, correctly
     *     kept across worker requests.
     */
    public static function resetForRequest(): void
    {
        // Fresh shared HTTP objects (legacy consumers: Kernel::request()/
        // response()/session(), BaseController, closure-fallback responses).
        static::$session = null;
        static::$request = null;
        static::$response = null;
        self::structureBone();

        // Fresh log correlation id: the next log write (any channel) mints a
        // new extra.request_id for the new request.
        \Ions\Bundles\RequestIdProcessor::reset();

        if (!self::isBooted()) {
            return;
        }

        // Fresh framework session; keep the SessionManager binding itself.
        if (static::$app->bound('session')) {
            /** @var \Ions\Session\SessionManager $manager */
            $manager = static::$app->get('session');
            $manager->renew();

            // Re-point the shared RequestStack at the new session so the CSRF
            // token storage (SessionTokenStorage reads through this stack) and
            // any other stack consumer see the fresh session.
            if (static::$app->bound('request_stack')) {
                /** @var \Symfony\Component\HttpFoundation\RequestStack $stack */
                $stack = static::$app->get('request_stack');
                $current = $stack->getCurrentRequest();
                if ($current !== null) {
                    $current->setSession($manager->getSession());
                } else {
                    $request = new Request();
                    $request->setSession($manager->getSession());
                    $stack->push($request);
                }
            }
        }

        // Refresh the per-request Twig globals on the shared Environment —
        // only when it has already been built; an unresolved 'view.env' will
        // pick up fresh values at build time anyway.
        if (static::$app->bound('view.env') && static::$app->resolved('view.env')) {
            try {
                /** @var \Twig\Environment $env */
                $env = static::$app->get('view.env');
                /** @var \Ions\View\ViewFactory $factory */
                $factory = static::$app->get('view');
                $factory->refreshRequestGlobals($env);
            } catch (Throwable $e) {
                // Never let a view refresh failure break request handling.
                try {
                    \Ions\Bundles\Logs::create('view.log')->warning('refreshRequestGlobals failed during resetForRequest: ' . $e->getMessage());
                } catch (Throwable) {
                    // ignore logging failures
                }
            }
        }

        // Bounded query log: flush so an enabled log never grows across requests.
        if (config('database.query_log', false) && static::$app->bound('db')) {
            try {
                static::$app->get('db')->getConnection()->flushQueryLog();
            } catch (Throwable) {
                // No live connection — nothing to flush.
            }
        }
    }

    /**
     * @return Request
     */
    private static function capture(): Request
    {
        Request::enableHttpMethodParameterOverride();
        return Request::createFromBase(Request::createFromGlobals());
    }

    /**
     * @return Config
     */
    public static function config(): Config
    {
        return static::$config;
    }

    /**
     * @return RouteCollection
     */
    public static function RouteCollection(): RouteCollection
    {
        return static::$collection;
    }

    /**
     * @return void
     */
    private static function Container(): void
    {
        static::$app = new Container();
        // Facade::setFacadeApplication() is typed for the Illuminate Foundation
        // Application, but the framework intentionally drives Illuminate facades
        // with its own container, which satisfies the contracts they actually use.
        /** @phpstan-ignore argument.type */
        Facade::setFacadeApplication(static::$app);

        // Bind the container to itself under its concrete class and the PSR /
        // Illuminate container contracts so libraries that type-hint a Container
        // (e.g. the queue's CallQueuedHandler) resolve the live Ions container.
        static::$app->instance(Container::class, static::$app);
        static::$app->instance(\Illuminate\Container\Container::class, static::$app);
        static::$app->instance(\Illuminate\Contracts\Container\Container::class, static::$app);

        // These inline bindings MUST stay here: captureConfig() calls Storage::files()
        // which requires 'filesystem'/'files' to exist BEFORE providers are bootstrapped.
        // FilesystemProvider's bound() guard makes it a harmless no-op at provider time.
        if (!static::$app->has('filesystem')) {
            static::$app->singleton('filesystem', function () {
                return new Filesystem();
            });
        }
        if (!static::$app->has('files')) {
            static::$app->singleton('files', function () {
                return new Filesystem();
            });
        }
    }

    /**
     * Returns the default list of framework service providers — the base of
     * every provider list (explicit 'app.providers', Discovery::providers()
     * or the pure-defaults fallback when 'app.discovery' is false).
     *
     * @return class-string<\Ions\Container\ServiceProvider>[]
     */
    public static function defaultProviders(): array
    {
        return [
            \Ions\Providers\ConfigProvider::class,
            \Ions\Providers\LogProvider::class,
            \Ions\Providers\FilesystemProvider::class,
            \Ions\Providers\SessionProvider::class,
            \Ions\Providers\DatabaseProvider::class,
            \Ions\Providers\CacheProvider::class,
            \Ions\Providers\EventProvider::class,
            \Ions\Providers\QueueProvider::class,
            \Ions\Providers\AuthProvider::class,
            \Ions\Providers\MailProvider::class,
            \Ions\Providers\NotificationProvider::class,
            \Ions\Providers\HttpClientProvider::class,
            \Ions\Providers\SecurityProvider::class,
            \Ions\Providers\ScheduleProvider::class,
            \Ions\Providers\ViewProvider::class,
        ];
    }

    /**
     * Two-pass provider bootstrap: all register() first, then all boot().
     *
     * Provider list resolution:
     *   - 'app.providers' set      → used verbatim (full explicit control, BC);
     *                                 no discovery scan runs at all.
     *   - unset + discovery on     → the discover:cache file
     *                                 (var/cache/providers.php) when it exists
     *                                 and APP_DEBUG is off: one require, zero
     *                                 scans (Discovery::cachedProviders());
     *                                 otherwise live Discovery::providers():
     *                                 framework defaults + composer
     *                                 extra.ions.providers + host
     *                                 {app|src}/Providers scan.
     *   - 'app.discovery' => false → pure defaultProviders().
     *
     * Debug bypasses the providers cache exactly like the route/config caches.
     * The cache never invalidates itself: re-run `ions optimize` (or
     * `discover:cache`) after composer install/update and after adding or
     * removing providers; stale cached FQCNs are filtered with a logged
     * warning, never a fatal.
     *
     * Runs inside the boot() try block so provider failures route through failBoot().
     *
     * @return void
     */
    private static function bootProviders(): void
    {
        /** @var class-string<\Ions\Container\ServiceProvider>[]|null $classes */
        $classes = config('app.providers');
        if (!is_array($classes)) {
            $classes = config('app.discovery', true)
                ? (Discovery::cachedProviders() ?? Discovery::providers())
                : self::defaultProviders();
        }
        $providers = array_map(static fn ($c) => new $c(static::$app), $classes);
        foreach ($providers as $p) {
            $p->register();
        }
        foreach ($providers as $p) {
            $p->boot();
        }
    }

    /**
     * @return void
     */
    private static function preloads(): void
    {
        $loadsFiles = static::config()->get('app.preloads');
        if (!empty($loadsFiles)) {
            foreach ($loadsFiles as $loads_file) {
                if (Storage::exists(Path::src($loads_file))) {
                    include_once Path::src($loads_file);
                }
            }
        }
    }

    /**
     * @return void
     */
    private static function captureConfig(): void
    {
        if (empty(static::$config) && !static::$config instanceof Config) {
            // Cached config (config:cache) — one require instead of reading
            // and including every config/*.php file. Debug always reads live.
            $cacheFile = Path::cache('config.php');
            if (!env('APP_DEBUG', false) && is_file($cacheFile)) {
                $cached = require $cacheFile;
                if (is_array($cached)) {
                    static::$config = new Config($cached);

                    return;
                }
            }

            $configFiles = Storage::files(Path::config());
            $configs = [];
            foreach ($configFiles as $config_file) {
                $configs[File::name($config_file)] = include($config_file);
            }
            static::$config = new Config($configs);
        }
    }

    /**
     * Handle a fatal boot/config error: log it (best-effort), then either
     * re-throw the original throwable when APP_DEBUG is on or testing mode is
     * armed (so developers / the test runner see the real cause) or die with
     * a generic 500 in production.
     *
     * @throws \Throwable when APP_DEBUG is truthy or resetForTesting() has run
     */
    private static function failBoot(\Throwable $e, string $context): never
    {
        // best-effort logging; never let logging failure mask the original error
        try {
            \Ions\Bundles\Logs::create('boot.log')->error($context . ': ' . $e->getMessage(), ['exception' => (string) $e]);
        } catch (\Throwable) {
            // ignore logging failures
        }
        if (self::$testing || env('APP_DEBUG', false)) {
            throw $e;
        }
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
        }
        die($context);
    }

    /**
     * @return void
     */
    private static function structureBone(): void
    {
        if (static::$session === null) {
            // Under the CLI SAPI (e.g. Pest/PHPUnit) native session storage
            // cannot start without warnings ("headers already sent").  Use an
            // in-memory MockArraySessionStorage in that environment so the
            // Kernel can boot cleanly; real web requests keep the default
            // NativeSessionStorage behaviour.
            if (PHP_SAPI === 'cli') {
                static::$session = new Session(
                    new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
                );
            } else {
                static::$session = new Session();
            }
            if (!static::$session->isStarted()) {
                static::$session->start();
            }
        }

        if (static::$request === null) {
            static::$request = self::capture();
        }

        if (static::$response === null) {
            static::$response = new Response();
        }
    }

    /**
     * use to access app session
     * @return Session
     */
    public static function session(): Session
    {
        return static::$session;
    }

    /**
     * use to access app request
     * @return Request
     */
    public static function request(): Request
    {
        return static::$request;
    }

    /**
     * use to access app response
     * @return Response
     */
    public static function response(): Response
    {
        return static::$response;
    }

    /**
     * use to access app container
     * @return Container
     */
    public static function app(): Container
    {
        return static::$app;
    }

    /**
     * Apply security headers and send the given response.
     *
     * @param SymfonyResponse|null $response When null, falls back to static::$response (legacy path).
     */
    public static function sendResponse(?SymfonyResponse $response = null): void
    {
        $toSend = $response ?? static::$response;
        SecurityHeaders::apply($toSend, static::$request);
        $toSend->send();
    }

    /**
     * Factory for the ExceptionHandler.
     *
     * A lightweight new instance is sufficient here; if the container ever
     * needs to bind a custom subclass, resolve it from static::$app instead.
     */
    private static function exceptionHandler(): ExceptionHandler
    {
        return new ExceptionHandler();
    }

    /**
     * Handle a request through the middleware pipeline and return a Response.
     *
     * This is the primary entry point for request handling.  It never exits/dies;
     * all error conditions — including HttpException from abort() and any
     * uncaught Throwable thrown inside a controller or middleware — are routed
     * through ExceptionHandler and returned as a proper Response.
     *
     * @param Request $request
     * @param string  $namespace Optional controller namespace prefix.
     * @return SymfonyResponse
     */
    public static function handle(Request $request, string $namespace = ''): SymfonyResponse
    {
        // Keep the shared request static in sync with the request actually
        // being handled: legacy consumers (Kernel::request(), ApiController,
        // csrfCheck(), IonUpload, …) must see the CURRENT request — essential
        // in worker mode where the boot-time capture is stale.
        static::$request = $request;

        // Re-apply trusted proxies against THIS request: boot() already set
        // them for the classic-FPM case, but the '*' wildcard must resolve to
        // the peer actually connecting NOW (worker mode handles many peers per
        // process; at CLI boot $_SERVER['REMOTE_ADDR'] does not even exist).
        self::applyTrustedProxies($request);

        // Determine group from first path segment.
        $targetFolder = $request->segment(1) === 'api' ? 'api' : 'web';
        if ($targetFolder === 'api') {
            $namespace .= 'Api\\';
        }

        try {
            // Maintenance mode (10.8) — gated BEFORE routing so every route
            // (web and api) is covered. Costs one file_exists() per request
            // when the app is live. The 503 throws through this try block so
            // the ExceptionHandler renders it (errors/503.twig themeable,
            // standard JSON shape for api); the secret-bypass URL returns a
            // redirect directly. /up stays reachable (liveness probe).
            if (MaintenanceMode::active()
                && ($redirect = MaintenanceMode::gate($request)) !== null) {
                self::fireRequestHandled($request, $redirect);
                return $redirect;
            }

            $context = new RequestContext();
            $context->fromRequest($request);

            // Prefer the compiled route cache (route:cache, non-debug only);
            // fall back to live capture + UrlMatcher otherwise.
            $routeMiddlewareNames = [];
            $compiled = self::compiledRoutes($targetFolder);
            if ($compiled !== null) {
                $matcher = new CompiledUrlMatcher($compiled, $context);
                $matcherParams = $matcher->match($context->getPathInfo());
                $routeMiddlewareNames = (array) ($matcherParams['_middleware'] ?? []);
                unset($matcherParams['_middleware']);
            } else {
                $routes = self::routes($targetFolder);
                $matcher = new UrlMatcher($routes, $context);
                $matcherParams = $matcher->match($context->getPathInfo());
                if (isset($matcherParams['_route'])) {
                    $matched = $routes->get($matcherParams['_route']);
                    $routeMiddlewareNames = (array) ($matched?->getOption('middleware') ?? []);
                }
            }

            // Route placeholder values (9.3 method injection) — matcher params
            // minus routing internals, shared by both terminal styles.
            $routeParams = self::routeParameters($matcherParams);

            // Resolve the terminal callable.
            if ($matcherParams['_controller'] instanceof Closure) {
                $closure = $matcherParams['_controller'];
                $terminal = function (Request $r) use ($closure, $routeParams): SymfonyResponse {
                    $args = (new ActionArgumentResolver(static::$app))
                        ->resolve(new \ReflectionFunction($closure), $r, $routeParams);

                    return ResponseNormalizer::normalize($closure(...$args), $r);
                };
            } else {
                // Set Vary headers on the shared response (preserving old behaviour).
                static::$response->setVary(['Accept-Encoding', 'gzip, compress, br']);
                static::$response->setVary(['Content-Encoding', 'br']);

                [$controller, $method] = self::handleRouteRequest($matcherParams, $namespace);
                $terminal = new ControllerDispatcher(static::$app, $controller, $method, $routeParams);
            }

            // Build middleware stack for the group.
            $stack = config('app.middleware', self::defaultMiddleware())[$targetFolder] ?? [];

            // Append per-route middleware (runs closest to the controller).
            // resolveMiddleware() fails closed: an unresolvable name throws
            // (rendered as a 500) — explicitly attached middleware is never
            // silently dropped.
            $routeMiddleware = [];
            foreach ($routeMiddlewareNames as $name) {
                $routeMiddleware[] = self::resolveMiddleware((string) $name);
            }
            $stack = array_merge($stack, $routeMiddleware);

            $response = (new Pipeline($stack, $terminal))->handle($request);

            // Preserve old cache-control headers for non-error web responses —
            // but never for redirects: a publicly cacheable 3xx would let a
            // shared cache/CDN serve one user's redirect (and its per-user
            // Location) to other users. Responses that explicitly opted OUT of
            // caching (no-store, e.g. the /up health probe) are respected:
            // setPublic()/setMaxAge() would strip the directive and let a CDN
            // mask an outage with a cached body.
            if ($targetFolder === 'web' && !$response->isRedirection()
                && !$response->headers->hasCacheControlDirective('no-store')) {
                $response->setPublic();
                $response->setMaxAge(3600);
                $response->headers->addCacheControlDirective('must-revalidate', true);
            }

            self::fireRequestHandled($request, $response);

            return $response;

        } catch (NoConfigurationException $e) {
            $response = self::exceptionHandler()->render(new NotFoundHttpException('Page route not found', $e), $request);
            self::fireRequestHandled($request, $response);
            return $response;
        } catch (MethodNotAllowedException $e) {
            $response = self::exceptionHandler()->render(new MethodNotAllowedHttpException([], 'Method not allowed', $e), $request);
            self::fireRequestHandled($request, $response);
            return $response;
        } catch (ResourceNotFoundException $e) {
            $response = self::exceptionHandler()->render(new NotFoundHttpException('Page route not found', $e), $request);
            self::fireRequestHandled($request, $response);
            return $response;
        } catch (Throwable $e) {
            $response = self::exceptionHandler()->render($e, $request);
            self::fireRequestHandled($request, $response);
            return $response;
        }
    }

    /**
     * Fire the framework's RequestHandled event in a fire-and-continue manner:
     * the dispatcher (and any listener) must never break the response, so all
     * failures — including the events binding being absent (e.g. in a partially
     * booted test) — are swallowed.
     */
    private static function fireRequestHandled(Request $request, SymfonyResponse $response): void
    {
        try {
            if (!static::$app->bound('events')) {
                return;
            }
            /** @var \Illuminate\Contracts\Events\Dispatcher $events */
            $events = static::$app->get('events');
            $events->dispatch(new RequestHandled($request, $response));
        } catch (Throwable) {
            // Intentionally ignored: lifecycle events are best-effort.
        }
    }

    /**
     * Send a response, optionally capturing from the request first.
     *
     * This is the entry-point used by new front controllers that want a
     * send-and-done call without needing to assemble a Request themselves.
     *
     * @param Request|null $request When null, the request is captured from globals.
     * @param string       $namespace
     * @return void
     */
    public static function run(?Request $request = null, string $namespace = ''): void
    {
        static::sendResponse(static::handle($request ?? self::capture(), $namespace));
    }

    /**
     * BC shim — preserves the old public entry-point for existing front controllers.
     *
     * Previously: matched route, handled closure with exit(), sent response.
     * Now:        thin wrapper around run() which calls handle() internally.
     *
     * @param string $namespace
     * @return void
     */
    public static function make(string $namespace = ''): void
    {
        static::run(null, $namespace);
    }

    /**
     * Extract route placeholder values from matcher params: drop routing
     * internals (underscore-prefixed keys such as _route/_controller) and the
     * legacy `id => 0` placeholder, mirroring handleRouteRequest()'s filter.
     *
     * Return normalization itself lives in Ions\Http\ResponseNormalizer —
     * the single normalizer shared by closure routes and ControllerDispatcher
     * (9.3 unification).
     *
     * @param array<string, mixed> $matcherParams
     * @return array<string, mixed>
     */
    private static function routeParameters(array $matcherParams): array
    {
        return Arr::where($matcherParams, static function ($value, $key) {
            return !str_starts_with((string) $key, '_') && !($key === 'id' && $value === 0);
        });
    }

    /**
     * Apply config('app.trusted_proxies') to the Request CLASS static.
     *
     * Entries are proxy IPs or CIDR ranges passed straight to Symfony's
     * Request::setTrustedProxies(). The wildcard '*' (Laravel parity) means
     * "trust the directly connecting peer": with a request at hand its
     * REMOTE_ADDR is used; otherwise Symfony's literal 'REMOTE_ADDR' token is
     * passed, which Symfony substitutes from $_SERVER at set time (classic
     * FPM boot) and silently drops when unavailable (CLI) — which is why
     * handle() re-applies this per request.
     *
     * No-op when the config key is empty: serving directly (no proxy) needs
     * no trust, and X-Forwarded-* headers from clients stay untrusted.
     *
     * @param Request|null $request The request being handled, when available.
     * @return void
     */
    private static function applyTrustedProxies(?Request $request = null): void
    {
        TrustedProxies::apply($request);
    }

    /**
     * Return the per-group default middleware stacks.
     *
     * Config may override this via config('app.middleware').
     *
     * @return array<string, list<\Ions\Http\Middleware\MiddlewareInterface>>
     */
    private static function defaultMiddleware(): array
    {
        return MiddlewareStack::defaults(static::$app);
    }

    /**
     * Build a Jwt instance from environment / config, or return null when the
     * signing key is absent or too short (< 32 bytes).
     *
     * Never throws — a missing or short key simply disables JWT signing.
     * Public so that AuthProvider (and other callers) can use it as a factory.
     *
     * @return Jwt|null
     */
    public static function buildJwt(): ?Jwt
    {
        return JwtFactory::build(isset(static::$app) ? static::$app : null);
    }

    /**
     * Resolve a PER-ROUTE middleware name (FQCN or alias) to a MiddlewareInterface
     * instance.
     *
     * Resolution order:
     *   1. If $name exists in config('app.middleware_aliases'), use the mapped class-string.
     *   2. Otherwise treat $name itself as a class-string.
     *   3. Instantiate via the container; verify it implements MiddlewareInterface.
     *
     * Failure policy — FAIL CLOSED, in both debug and production:
     *   Per-route middleware is attached explicitly (->middleware([...]) or the
     *   '_middleware' route option) and is almost always a security gate
     *   ('signed', 'throttle', auth). Silently dropping an unresolvable entry
     *   would serve the route UNPROTECTED — an unsigned request would get a 200.
     *   So an unresolvable name always throws InvalidArgumentException, which
     *   Kernel::handle() routes through the ExceptionHandler as a 500 (generic
     *   body in production, full detail in debug). The cause is additionally
     *   logged in production since the generic error page hides it.
     *
     *   Note on group stacks: the per-group middleware stacks
     *   (config('app.middleware') / defaultMiddleware()) are arrays of
     *   already-constructed MiddlewareInterface instances and never pass
     *   through this resolver — there is no name-resolution step to fail for
     *   them, so this policy governs per-route middleware only.
     *
     * Public since 9.3: ControllerDispatcher resolves per-controller
     * middleware() entries through this same fail-closed policy.
     *
     * @param string $name FQCN or alias string.
     * @return \Ions\Http\Middleware\MiddlewareInterface
     * @throws \InvalidArgumentException when the middleware cannot be resolved.
     */
    public static function resolveMiddleware(string $name): \Ions\Http\Middleware\MiddlewareInterface
    {
        return MiddlewareStack::resolve(static::$app, $name);
    }

    /**
     * Return the route collection for a group, capturing it at most once per
     * process. Re-booting the kernel (boot()/resetForTesting()) clears the
     * cache, so workers/tests that re-boot always get fresh routes.
     *
     * @param string $targetFolder 'web' or 'api'
     * @return RouteCollection
     */
    private static function routes(string $targetFolder): RouteCollection
    {
        return static::$routeCollections[$targetFolder] ??= self::buildRouteCollection($targetFolder);
    }

    /**
     * Public accessor for the memoized per-group route collection ('web' or
     * 'api'), capturing it on first use. URL generation (signedRoute()) needs
     * this because routes declared in routes/{group}.php or via attributes
     * only live in the group collections — the shared collection is restored
     * to its pre-include snapshot after each group build.
     *
     * @param string $targetFolder 'web' or 'api'
     * @return RouteCollection
     */
    public static function routesFor(string $targetFolder): RouteCollection
    {
        return self::routes($targetFolder);
    }

    /**
     * Build the full route collection for a group from scratch: routes already
     * registered on the shared collection (e.g. by App\Booting), the group's
     * routes/{group}.php or .yaml file, attribute routes, and the cron
     * schedule route.
     *
     * Public so cache/inspection commands (route:cache) can reuse the exact
     * capture logic the request path dispatches with.
     *
     * Each group build is fully isolated: the shared collection is snapshot-
     * and-restored around the routes file include so that web routes loaded by
     * routes/web.php are never visible in a subsequent api build (and vice
     * versa). This prevents cross-group contamination when the command builds
     * both groups in a single process.
     *
     * @param string $targetFolder 'web' or 'api'
     * @return RouteCollection
     */
    public static function buildRouteCollection(string $targetFolder): RouteCollection
    {
        $routes = new RouteCollection();

        // Snapshot the shared collection BEFORE loading the routes file so
        // that the delta (only this group's routes) can be extracted and the
        // shared collection can be restored to its original state afterwards.
        // This prevents routes loaded for group A from leaking into group B
        // when both groups are built in the same process (e.g. route:cache).
        $shared = static::RouteCollection();
        $snapshot = $shared->all();

        // Copy routes that were registered before any file was included
        // (e.g. via Route:: calls in App\Booting or provider boot methods).
        foreach ($snapshot as $name => $route) {
            $routes->add($name, $route);
        }

        $phpFile = Path::route($targetFolder . '.php');
        $yamlFile = Path::route($targetFolder . '.yaml');

        if (file_exists($phpFile)) {
            // The Route facade appends to the shared collection; extract the
            // delta this file produced into the group collection, then RESTORE
            // the shared collection to its pre-include snapshot so the next
            // group build starts from a clean state.
            require $phpFile;
            foreach ($shared->all() as $name => $route) {
                if (!array_key_exists($name, $snapshot)) {
                    $routes->add($name, $route);
                }
            }
            // Restore shared collection to pre-include state.
            $freshShared = new RouteCollection();
            foreach ($snapshot as $name => $route) {
                $freshShared->add($name, $route);
            }
            static::$collection = $freshShared;
        } elseif (file_exists($yamlFile)) {
            $fileLocator = new FileLocator([__DIR__]);
            $loader = new YamlFileLoader($fileLocator);
            $routes->addCollection($loader->load($yamlFile));
        }

        // attributes routing
        $targetFolder === 'web' ? $attributesPath = Path::src('Http') : $attributesPath = Path::api();
        if (Storage::exists($attributesPath)) {
            $fileLocator = new FileLocator($attributesPath);
            $loader = new AttributeDirectoryLoader($fileLocator, new AttributeRouteControllerLoader());
            $attributesRoutes = $loader->load($attributesPath);
            if ($attributesRoutes !== null && !empty($attributesRoutes->all())) {
                $routes->addCollection($attributesRoutes);
            }
        }

        // route for schedule cron job — the framework controller decides at
        // hit time between the new-style scheduler (boot(Scheduler)) and the
        // legacy 'App\Schedule::boot' dispatch (zero-parameter boot). A string
        // controller (not a Closure) keeps the route compatible with route:cache.
        $routes->add(Str::random(10) . '_schedule', new Route(
            '/cron/schedule',
            ['_controller' => \Ions\Schedule\Http\WebCronController::class . '::run']
        ));

        // Built-in /up health endpoint (10.6) — same controller-string
        // pattern; disabled entirely (404) with app.health.enabled => false.
        if (config('app.health.enabled', true) !== false) {
            $routes->add(Str::random(10) . '_health', new Route(
                '/up',
                ['_controller' => \Ions\Http\HealthController::class . '::up']
            ));
        }

        // Route::fallback() catch-all (10.7) — appended LAST so every real
        // route wins (the UrlMatcher tries routes in definition order;
        // '/{fallback}' with a '.*' requirement matches any GET path,
        // including '/'). Consuming (not peeking) scopes the fallback to the
        // group whose routes file registered it.
        $fallbackHandler = \Ions\Bundles\Route::consumeFallback();
        if ($fallbackHandler !== null) {
            $routes->add(Str::random(10) . '_fallback', new Route(
                path: '/{fallback}',
                defaults: ['_controller' => $fallbackHandler],
                requirements: ['fallback' => '.*'],
                methods: ['GET']
            ));
        }

        return $routes;
    }

    /**
     * Load the compiled route cache for a group, when applicable.
     *
     * The cache only applies when APP_DEBUG is off and
     * var/cache/routes/{group}.php exists (written by route:cache). The loaded
     * compiled array is memoized per process; the cheap is_file() check keeps
     * route:clear effective within a running process.
     *
     * @param string $targetFolder 'web' or 'api'
     * @return array|null Compiled routes array for CompiledUrlMatcher, or null.
     */
    private static function compiledRoutes(string $targetFolder): ?array
    {
        if (env('APP_DEBUG', false)) {
            return null;
        }

        $file = Path::cache('routes' . DIRECTORY_SEPARATOR . $targetFolder . '.php');
        if (!is_file($file)) {
            unset(static::$compiledRoutes[$targetFolder]);
            return null;
        }

        if (!array_key_exists($targetFolder, static::$compiledRoutes)) {
            $loaded = require $file;
            static::$compiledRoutes[$targetFolder] = is_array($loaded) ? $loaded : false;
        }

        $cached = static::$compiledRoutes[$targetFolder];

        return $cached === false ? null : $cached;
    }

    /**
     * @param array $matcherParams
     * @param string $namespace
     * @return array
     */
    private static function handleRouteRequest(array $matcherParams, string $namespace): array
    {
        // Pure parse + namespacing (controller-string resolution lives in the
        // ControllerResolver collaborator). The needles list, the config write
        // and the request-attribute mutations stay here so the per-request
        // side-effect surface is unchanged. The resolver does not read
        // config('app._method'), so writing it after the call is behavior-
        // identical to the original (which wrote it before the filter/adds);
        // nothing in between observes the key.
        $needles = array_merge(['super', 'api', 'Api'], static::config()->get('app.needles', []));

        [$controller, $method, $matcherParams] = ControllerResolver::resolve($matcherParams, $namespace, $needles);

        static::config()->set('app._method', $method);

        // add matcher to request parameters
        static::$request->attributes->add($matcherParams);

        $slice = Str::afterLast($controller, '\\');
        static::$request->attributes->add(['_controller_name' => $slice, '_method_name' => $method]);

        return [$controller, $method];
    }
}
