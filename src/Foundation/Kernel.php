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
use Ions\Http\ExceptionHandler;
use Ions\Http\Middleware\AuthMiddleware;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Http\Middleware\CorsMiddleware;
use Ions\Http\Middleware\CsrfMiddleware;
use Ions\Http\Middleware\Pipeline;
use Ions\Http\Middleware\SecurityHeadersMiddleware;
use Ions\Http\Middleware\StartSessionMiddleware;
use Ions\Http\Middleware\TrustedHostMiddleware;
use Ions\Security\ArrayRevocationStore;
use Ions\Security\Jwt;
use Ions\Security\RevocationStore;
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
        static::$config = [];
        static::$session = null;
        static::$request = null;
        static::$response = null;
        static::$routeCollections = [];
        static::$compiledRoutes = [];
        \Ions\Bundles\Path::resetBasePath();
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
     * Returns the default list of service providers registered when no
     * 'app.providers' config key is present.
     *
     * @return class-string<\Ions\Container\ServiceProvider>[]
     */
    private static function defaultProviders(): array
    {
        return [
            \Ions\Providers\ConfigProvider::class,
            \Ions\Providers\FilesystemProvider::class,
            \Ions\Providers\SessionProvider::class,
            \Ions\Providers\DatabaseProvider::class,
            \Ions\Providers\CacheProvider::class,
            \Ions\Providers\EventProvider::class,
            \Ions\Providers\QueueProvider::class,
            \Ions\Providers\AuthProvider::class,
            \Ions\Providers\MailProvider::class,
            \Ions\Providers\ViewProvider::class,
        ];
    }

    /**
     * Two-pass provider bootstrap: all register() first, then all boot().
     * Reads 'app.providers' from config (falls back to defaultProviders()).
     * Runs inside the boot() try block so provider failures route through failBoot().
     *
     * @return void
     */
    private static function bootProviders(): void
    {
        /** @var class-string<\Ions\Container\ServiceProvider>[] $classes */
        $classes = config('app.providers', self::defaultProviders());
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
     * re-throw the original throwable when APP_DEBUG is on (so developers see
     * the real cause) or die with a generic 500 in production.
     *
     * @throws \Throwable when APP_DEBUG is truthy
     */
    private static function failBoot(\Throwable $e, string $context): never
    {
        // best-effort logging; never let logging failure mask the original error
        try {
            \Ions\Bundles\Logs::create('boot.log')->error($context . ': ' . $e->getMessage(), ['exception' => (string) $e]);
        } catch (\Throwable) {
            // ignore logging failures
        }
        if (env('APP_DEBUG', false)) {
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
        SecurityHeaders::apply($toSend);
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
        // Determine group from first path segment.
        $targetFolder = $request->segment(1) === 'api' ? 'api' : 'web';
        if ($targetFolder === 'api') {
            $namespace .= 'Api\\';
        }

        try {
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

            // Resolve the terminal callable.
            if ($matcherParams['_controller'] instanceof Closure) {
                $closure = $matcherParams['_controller'];
                $terminal = function (Request $r) use ($closure): SymfonyResponse {
                    $result = $closure($r);
                    return self::normalizeToResponse($result);
                };
            } else {
                // Set Vary headers on the shared response (preserving old behaviour).
                static::$response->setVary(['Accept-Encoding', 'gzip, compress, br']);
                static::$response->setVary(['Content-Encoding', 'br']);

                [$controller, $method] = self::handleRouteRequest($matcherParams, $namespace);
                $terminal = new ControllerDispatcher(static::$app, $controller, $method);
            }

            // Build middleware stack for the group.
            $stack = config('app.middleware', self::defaultMiddleware())[$targetFolder] ?? [];

            // Append per-route middleware (runs closest to the controller).
            $routeMiddleware = [];
            foreach ($routeMiddlewareNames as $name) {
                $resolved = self::resolveMiddleware((string) $name);
                if ($resolved !== null) {
                    $routeMiddleware[] = $resolved;
                }
            }
            $stack = array_merge($stack, $routeMiddleware);

            $response = (new Pipeline($stack, $terminal))->handle($request);

            // Preserve old cache-control headers for non-error web responses.
            if ($targetFolder === 'web') {
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
     * Normalize a controller/closure return value to a Response.
     *
     * If the return value is already a Symfony Response it is returned as-is.
     * Otherwise the shared kernel Response (which the closure may have written
     * to via Kernel::response()) is returned as the fallback.
     *
     * @param mixed $result
     * @return SymfonyResponse
     */
    private static function normalizeToResponse(mixed $result): SymfonyResponse
    {
        if ($result instanceof SymfonyResponse) {
            return $result;
        }

        return static::$response;
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
        // Prefer the container-bound jwt (registered by AuthProvider); fall back to
        // direct construction so auth works even when AuthProvider is not in the list.
        $jwt = static::$app->has('jwt') ? static::$app->get('jwt') : self::buildJwt();
        /** @var \Ions\Security\Jwt|null $jwt */

        $userProvider = static::$app->has('user_provider') ? static::$app->get('user_provider') : null;
        /** @var \Ions\Auth\Contracts\UserProvider|null $userProvider */

        $web = [
            new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
            new SecurityHeadersMiddleware(),
            new CorsMiddleware((array) config('app.cors', [])),
        ];
        // Start the session early (before CSRF) so CSRF and downstream code share it.
        if (static::$app->has('session')) {
            /** @var \Ions\Session\SessionManager $session */
            $session = static::$app->get('session');
            $web[] = new StartSessionMiddleware($session);
        }
        if (config('app.csrf.enabled', true) && static::$app->has('csrf')) {
            /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfManager */
            $csrfManager = static::$app->get('csrf');
            $web[] = new CsrfMiddleware($csrfManager);
        }

        return [
            'web' => $web,
            'api' => [
                new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware((array) config('app.cors', [])),
                new AuthMiddleware($jwt, $userProvider, (array) config('app.auth.public_paths', [])),
            ],
        ];
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
        $secret = (string) env('APP_KEY', '');
        if (strlen($secret) < 32) {
            return null;
        }

        // Resolve an optional RevocationStore from the container.
        // When a 'revocation_store' binding is present (e.g. a cache-backed
        // CacheRevocationStore registered by the app), it is used; otherwise
        // the in-memory ArrayRevocationStore is used as the default.
        // Note: ArrayRevocationStore only persists within a single request.
        // For cross-request revocation (e.g. logout that survives restart),
        // bind a persistent RevocationStore implementation as 'revocation_store'.
        $store = null;
        if (isset(static::$app)) {
            if (static::$app->has('revocation_store')) {
                /** @var RevocationStore $store */
                $store = static::$app->get('revocation_store');
            } else {
                $store = new ArrayRevocationStore();
            }
        }

        try {
            return new Jwt(
                $secret,
                (string) env('APP_NAME', 'ions'),
                (string) env('APP_NAME', 'ions'),
                (int) config('app.jwt.ttl', 3600),
                (int) config('app.jwt.leeway', 0),
                $store,
                (int) config('app.jwt.refresh_ttl', Jwt::DEFAULT_REFRESH_TTL),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a middleware name (FQCN or alias) to a MiddlewareInterface instance.
     *
     * Resolution order:
     *   1. If $name exists in config('app.middleware_aliases'), use the mapped class-string.
     *   2. Otherwise treat $name itself as a class-string.
     *   3. Instantiate via the container; verify it implements MiddlewareInterface.
     *   4. Return null (unresolvable) rather than throw, so a bad alias never crashes the request.
     *
     * @param string $name FQCN or alias string.
     * @return \Ions\Http\Middleware\MiddlewareInterface|null
     */
    private static function resolveMiddleware(string $name): ?\Ions\Http\Middleware\MiddlewareInterface
    {
        /** @var array<string,string> $aliases */
        $aliases = (array) config('app.middleware_aliases', []);
        $class = $aliases[$name] ?? $name;

        if (!class_exists($class)) {
            return null;
        }

        try {
            $instance = static::$app->make($class);
        } catch (\Throwable) {
            return null;
        }

        if (!($instance instanceof \Ions\Http\Middleware\MiddlewareInterface)) {
            return null;
        }

        return $instance;
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
     * Build the full route collection for a group from scratch: routes already
     * registered on the shared collection (e.g. by App\Booting), the group's
     * routes/{group}.php or .yaml file, attribute routes, and the cron
     * schedule route.
     *
     * Public so cache/inspection commands (route:cache) can reuse the exact
     * capture logic the request path dispatches with.
     *
     * @param string $targetFolder 'web' or 'api'
     * @return RouteCollection
     */
    public static function buildRouteCollection(string $targetFolder): RouteCollection
    {
        $routes = new RouteCollection();

        // Routes registered at boot time directly on the shared collection
        // (via the Route facade outside a routes file) stay matchable.
        $shared = static::RouteCollection();
        $before = array_keys($shared->all());
        foreach ($shared->all() as $name => $route) {
            $routes->add($name, $route);
        }

        $phpFile = Path::route($targetFolder . '.php');
        $yamlFile = Path::route($targetFolder . '.yaml');

        if (file_exists($phpFile)) {
            // The Route facade appends to the shared collection; copy the
            // delta this file produced so the group collection stays complete.
            require $phpFile;
            foreach ($shared->all() as $name => $route) {
                if (!in_array($name, $before, true)) {
                    $routes->add($name, $route);
                }
            }
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

        // route for schedule cron job
        $routes->add(Str::random(10) . '_schedule', new Route('/cron/schedule', ['_controller' => 'App\Schedule::boot']));

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
        // check if using :: or @ for method
        if (str_contains($matcherParams['_controller'], '::')) {
            // action -> as text : NameController::action
            $exControllerMethod = explode('::', $matcherParams['_controller']);
        } elseif (str_contains($matcherParams['_controller'], '@')) {
            // action -> as text : NameController@action
            $exControllerMethod = explode('@', $matcherParams['_controller']);
        }

        $controller = $exControllerMethod[0] ?? $matcherParams['_controller'];
        $method = $exControllerMethod[1] ?? $matcherParams['method'];

        static::config()->set('app._method', $method);

        // remove id from parameters when 0 value
        $matcherParams = Arr::where($matcherParams, static function ($value, $key) {
            return !($key === 'id' && $value === 0);
        });

        // add matcher to request parameters
        static::$request->attributes->add($matcherParams);

        $needles = array_merge(['super', 'api', 'Api'], static::config()->get('app.needles', []));
        // add namespace to controller if didn't have
        if ($namespace && $controller !== 'App\Schedule' && !Str::contains($controller, $namespace)) {
            // check if super or api
            if (Str::contains($controller, $needles, true) || Str::contains($namespace, 'Api')) {
                $controller = $namespace . $controller;
            } else {
                $controller = $namespace . 'Controllers\\' . $controller;
            }
        }

        $slice = Str::afterLast($controller, '\\');
        static::$request->attributes->add(['_controller_name' => $slice, '_method_name' => $method]);

        return [$controller, $method];
    }
}
