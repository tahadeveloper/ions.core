<?php

namespace Ions\Foundation;

use App\Booting;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Ions\Bundles\MRoute;
use Ions\Bundles\AttributeRouteControllerLoader;
use Ions\Bundles\Path;
use Ions\Support\Arr;
use Ions\Support\Request;
use Ions\Support\Response;
use Ions\Support\Session;
use Ions\Support\Storage;
use Ions\Support\Str;
use JetBrains\PhpStorm\NoReturn;
use Spatie\Ignition\Ignition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
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
use Whoops\Handler\JsonResponseHandler;
use Whoops\Run;
use const EXTR_SKIP;

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
     * @return void
     */
    public static function boot(): void
    {
        try {
            static::$environmentPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR;

            static::structureBone();

            (Dotenv::createImmutable(realpath(static::$environmentPath), static::$envName))->safeLoad();

            static::Container();
            static::captureConfig();

            static::$collection = new RouteCollection();

            include_once Path::core('helpers.php');

        } catch (Throwable) {
            header('HTTP/1.1 500 Internal Server Error');
            die('Booting ions failed.');
        }

        if (class_exists(Booting::class)) {
            Booting::boot();
        }

        date_default_timezone_set(env('TIME_ZONE', 'Africa/Cairo'));

        static::preloads();
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
        $app = self::$app;
        /** @var Application $app */
        Facade::setFacadeApplication($app);
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
            try {
                $configFiles = Storage::files(Path::config());

                $configs = [];
                foreach ($configFiles as $config_file) {
                    $configs[File::name($config_file)] = include($config_file);
                }
                static::$config = new Config($configs);
            } catch (Throwable) {
                static::$config = [];
                die('Config options fail.');
            }
        }
    }

    /**
     * @return void
     */
    private static function structureBone(): void
    {
        if (empty(static::$session) && !static::$session instanceof Session) {
            static::$session = new Session();
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
     * handler error to display beautify.
     *
     * @return void
     */
    protected static function errorDebug(): void
    {
        if (env('APP_DEBUG', false) === true) {
            Ignition::make()
                ->applicationPath(realpath(Path::root('')))
                //->shouldDisplayException(!env('APP_DEBUG'))
                ->register();

        } else {
            ErrorHandler::register();
            DebugClassLoader::disable();
            HtmlErrorRenderer::setTemplate(Path::var('templates/Exception/error.html.php'));
        }
        ini_set("display_errors", env('APP_DEBUG', false));
    }

    /**
     * handler error to display beautify. with json
     *
     * @return void
     */
    protected static function errorDebugApi(): void
    {
        if (env('APP_DEBUG', false) === true) {
            $whoops = new Run;
            $whoops->pushHandler(new JsonResponseHandler());
            $whoops->pushHandler(function ($e) {
                $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 501;
                static::response()->setStatusCode($statusCode)->send();
            });
            $whoops->register();
        } else {
            ErrorHandler::register();
            DebugClassLoader::disable();
            HtmlErrorRenderer::setTemplate(Path::var('templates/Exception/error.json.php'));
        }
        ini_set("display_errors", env('APP_DEBUG', false));
    }

    /**
     * @param array $context
     * @return string
     */
    private static function HtmlErrorRender(array $context = []): string
    {
        extract($context, EXTR_SKIP);
        ob_start();
        include Path::var('templates/Exception/error.html.php');
        return trim(ob_get_clean());
    }

    /**
     * Run app by route it with controller and method
     *
     * @param string $namespace
     * @return void
     */
    public static function make(string $namespace = ''): void
    {
        self::request()->wantsJson() ? static::errorDebugApi() : static::errorDebug();

        self::request()->segment(1) === 'api' ? $targetFolder = 'api' : $targetFolder = 'web';
        self::request()->segment(1) !== 'api' ?: $namespace .= 'Api\\';

        try {
            $routes = static::captureRoute($targetFolder);
            $context = new RequestContext();
            $context->fromRequest(static::$request);
            $matcher = new UrlMatcher($routes, $context);
            $matcherParams = $matcher->match($context->getPathInfo());

            // run closure #1st choice
            if ($matcherParams['_controller'] instanceof Closure) {
                $closure = $matcherParams['_controller'];
                $closure(static::$request);
                exit();
            }

            static::$response->setVary(['Accept-Encoding', 'gzip, compress, br']);
            static::$response->setVary(['Content-Encoding', 'br']);
            [$controller, $method] = self::handleRouteRequest($matcherParams, $namespace);

            self::instanceTheController($controller, $method);

            static::$response->setPublic();
            static::$response->setMaxAge(3600);
            static::$response->headers->addCacheControlDirective('must-revalidate', true);
            static::$response->send();

        } catch (NoConfigurationException) {
            self::makeError('No configurations found', 404);
        } catch (MethodNotAllowedException) {
            self::makeError('Method not allowed', 405);
        } catch (ResourceNotFoundException) {
            self::makeError('Page route not found', 404);
        }

    }

    /**
     * @param string $error
     * @param int $statusCode
     * @return void
     */
    #[NoReturn] private static function makeError(string $error, int $statusCode): void
    {
        if (self::request()->wantsJson()) {
            static::$response->setContent(toJson([
                'status' => 'error',
                'message' => $error,
                'code' => $statusCode,
            ]));
        } else {
            static::$response->setContent(static::HtmlErrorRender([
                'statusText' => $error,
                'statusCode' => $statusCode,
            ]));
        }
        static::$response->setStatusCode($statusCode);
        static::$response->send();
        die();
    }

    /**
     * @param string $targetFolder
     * @return RouteCollection
     */
    private static function captureRoute(string $targetFolder): RouteCollection
    {
        file_exists(Path::route($targetFolder . '.php')) ? $target = 'php' : $target = 'yaml';
        MRoute::$collection = new RouteCollection();

        if ($target === 'php') {
            include_once Path::route($targetFolder . '.' . $target);
            //$routes = MRoute::$collection;
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
     * @param mixed $controller
     * @param mixed $method
     * @return void
     */
    private static function instanceTheController(mixed $controller, mixed $method): void
    {
        // instance the controller
        $instance = new $controller();
        !method_exists($instance, '_initState') ?: $instance->_initState(static::$request);
        !method_exists($instance, '_loadInit') ?: $instance->_loadInit(static::$request);
        !method_exists($instance, '_loadedState') ?: $instance->_loadedState(static::$request);
        if (method_exists($instance, 'callAction')) {
            $instance->callAction($method, [static::$request]);
        } elseif (method_exists($instance, $method)) {
            $instance->{$method}(...array_values([static::$request]));
        }
        !method_exists($instance, '_endState') ?: $instance->_endState(static::$request);
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

        // secure app, accept request from app_url
        $host = static::$request->headers->get('host') . env('APP_FOLDER');
        // remove http:// or https:// from APP_URL
        $removeProtcals = ['http://', 'https://'];
        $appUrl = Str::remove($removeProtcals, env('APP_URL'));
        if ($host !== $appUrl) {
            throw new EncryptException('App host does not exist!.');
        }

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

        return array($controller, $method);
    }
}
