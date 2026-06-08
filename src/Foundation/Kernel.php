<?php

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
use Ions\Http\ExceptionHandler;
use Ions\Http\Middleware\AuthMiddleware;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Http\Middleware\CorsMiddleware;
use Ions\Http\Middleware\Pipeline;
use Ions\Http\Middleware\SecurityHeadersMiddleware;
use Ions\Http\Middleware\TrustedHostMiddleware;
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
use Symfony\Component\Routing\Loader\AnnotationDirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
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

            static::structureBone();

            (Dotenv::createImmutable(realpath(static::$environmentPath), static::$envName))->safeLoad();

            static::Container();
            static::captureConfig();

            static::$collection = new RouteCollection();

            include_once Path::core('helpers.php');

            $trustedHosts = config('app.trusted_hosts', []);
            if (!empty($trustedHosts)) {
                Request::setTrustedHosts($trustedHosts);
            }

            self::bootProviders();

        } catch (Throwable $e) {
            static::failBoot($e, 'Booting ions failed');
        }

        if (class_exists(Booting::class)) {
            Booting::boot();
        }

        date_default_timezone_set(env('TIME_ZONE', 'Africa/Cairo'));

        static::preloads();
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
            \Ions\Providers\DatabaseProvider::class,
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
        if (empty(static::$session) && !static::$session instanceof Session) {
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

        if (empty(static::$request) && !static::$request instanceof Request) {
            static::$request = static::capture();
        }

        if (empty(static::$response) && !static::$response instanceof Response) {
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
            $routes = static::captureRoute($targetFolder);
            $context = new RequestContext();
            $context->fromRequest($request);
            $matcher = new UrlMatcher($routes, $context);
            $matcherParams = $matcher->match($context->getPathInfo());

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
            if (isset($matcherParams['_route'])) {
                $matched = $routes->get($matcherParams['_route']);
                foreach ((array) ($matched?->getOption('middleware') ?? []) as $name) {
                    $resolved = self::resolveMiddleware((string) $name);
                    if ($resolved !== null) {
                        $routeMiddleware[] = $resolved;
                    }
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

            return $response;

        } catch (NoConfigurationException $e) {
            return self::exceptionHandler()->render(new NotFoundHttpException('Page route not found', $e), $request);
        } catch (MethodNotAllowedException $e) {
            return self::exceptionHandler()->render(new MethodNotAllowedHttpException([], 'Method not allowed', $e), $request);
        } catch (ResourceNotFoundException $e) {
            return self::exceptionHandler()->render(new NotFoundHttpException('Page route not found', $e), $request);
        } catch (Throwable $e) {
            return self::exceptionHandler()->render($e, $request);
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

        return [
            'web' => [
                new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware((array) config('app.cors', [])),
            ],
            'api' => [
                new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware((array) config('app.cors', [])),
                new AuthMiddleware($jwt),
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

        try {
            return new Jwt(
                $secret,
                (string) env('APP_NAME', 'ions'),
                (string) env('APP_NAME', 'ions'),
                (int) config('app.jwt.ttl', 3600),
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
     * @param string $targetFolder
     * @return RouteCollection
     */
    private static function captureRoute(string $targetFolder): RouteCollection
    {
        file_exists(Path::route($targetFolder . '.php')) ? $target = 'php' : $target = 'yaml';

        if ($target === 'php') {
            require Path::route($targetFolder . '.' . $target);
            $routes = static::RouteCollection();
        } else {
            $fileLocator = new FileLocator([__DIR__]);
            $loader = new YamlFileLoader($fileLocator);
            $routes = $loader->load(Path::route($targetFolder . '.' . $target));
        }

        // attributes routing
        $targetFolder === 'web' ? $attributesPath = Path::src('Http') : $attributesPath = Path::api();
        if (Storage::exists($attributesPath)) {
            $fileLocator = new FileLocator($attributesPath);
            $loader = new AnnotationDirectoryLoader($fileLocator, new AttributeRouteControllerLoader());
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
