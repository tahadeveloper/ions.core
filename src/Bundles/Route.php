<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Closure;
use InvalidArgumentException;
use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Support\Str;
use Symfony\Component\Routing\Route as SRoute;

/**
 * Class Route
 * @package Ions\Bundles
 * @method static Route get(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route post(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route put(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route delete(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route patch(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route options(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route any(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route match(array $methods, string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route prefix(string $name, ?string $controls = null, ?Closure $closure = null): static
 * @method Route group(Closure $closure): void
 * @method static Route resource(string $name, string $controller): static
 */
class Route extends Singleton
{
    private static array $prefix = [];
    private static array $controls = [];
    /** @var string[] Group name-prefix stack ('admin.' segments, 10.7). */
    private static array $namePrefixes = [];
    /** @var array<int, list<string>> Group middleware stack (10.7). */
    private static array $groupMiddleware = [];
    /** Deferred 404 catch-all; consumed by Kernel::buildRouteCollection(). */
    private static Closure|string|null $fallback = null;

    private array $route_details;
    /** @var SRoute[] */
    private array $lastRoutes = [];
    /** @var string[] Collection keys of $lastRoutes (parallel array) — name() re-keys through these. */
    private array $lastNames = [];

    /** True between prefix() and group(): name()/middleware() collect group attributes. */
    private bool $groupBuilder = false;
    private string $pendingNamePrefix = '';
    /** @var list<string> */
    private array $pendingMiddleware = [];

    public static function __callStatic(string $name, array $arguments)
    {
        $route = new self();
        $allowed_methods = ['get', 'post', 'put', 'patch', 'delete', 'options'];
        if (in_array($name, $allowed_methods)) {
            $route->inRoute($name, ...$arguments);
            $route_details = $route->route_details;
            $route->newRoute($route_details['name'], $route_details['path'], $route_details['controller'], $route_details['defaults'], $route_details['method'], $route_details['wheres']);
        } elseif ($name === 'any') {
            $route->inRoute([], ...$arguments);
            $route_details = $route->route_details;
            $route->newRoute($route_details['name'], $route_details['path'], $route_details['controller'], $route_details['defaults'], $route_details['method'], $route_details['wheres']);
        } elseif ($name === 'match') {
            $route->inRoute($arguments[0], ...array_slice($arguments, 1));
            $route_details = $route->route_details;
            $route->newRoute($route_details['name'], $route_details['path'], $route_details['controller'], $route_details['defaults'], $route_details['method'], $route_details['wheres']);
        } elseif ($name == 'prefix') {
            $route->_prefix(...$arguments);
        } elseif ($name == 'resource') {
            $route->_resource(...$arguments);
        } else {
            abort(500, 'Method not found');
        }
        return $route;
    }

    public function __call(string $name, array $arguments)
    {
        if ($name == 'group') {
            // Must run on THIS instance: the group builder's pending name
            // prefix / middleware (prefix()->name()->middleware()->group())
            // live on the instance prefix() returned.
            $this->_group(...$arguments);
        } else {
            abort(500, 'Method not found');
        }
    }

    /**
     * Attach middleware class names (or alias names) to this route — or, on a
     * group builder (between prefix() and group()), to every route registered
     * inside the group.
     *
     * The names are stored as the 'middleware' option on the underlying
     * Symfony Route object so Kernel::handle() can read them back when the
     * route is matched. Names MERGE with any middleware already on the route
     * (e.g. inherited from an enclosing group).
     *
     * @param list<string> $names FQCN or alias strings.
     * @return static
     */
    public function middleware(array $names): static
    {
        if ($this->groupBuilder) {
            $this->pendingMiddleware = array_merge($this->pendingMiddleware, $names);
            return $this;
        }

        foreach ($this->lastRoutes as $r) {
            $existing = (array) ($r->getOption('middleware') ?? []);
            $r->setOption('middleware', array_values(array_unique(array_merge($existing, $names))));
        }
        return $this;
    }

    /**
     * Name the just-added route fluently (10.7) — or, on a group builder, set
     * the name PREFIX prepended to every route named inside the group:
     *
     *   Route::get('/about', ...)->name('page.about');
     *   Route::prefix('/admin')->name('admin.')->group(...);
     *
     * The route was already added to the collection under its auto-generated
     * random key, so naming re-keys it: remove the auto name, re-add under the
     * new one (the route object — including any middleware()/where() already
     * applied — is unchanged). Group name prefixes in effect are prepended.
     * Chaining order with middleware()/where() is free. With resource()
     * (multiple routes) name() targets the most recently added route only.
     *
     * @return static
     */
    public function name(string $name): static
    {
        if ($this->groupBuilder) {
            $this->pendingNamePrefix = $name;
            return $this;
        }

        $index = array_key_last($this->lastRoutes);
        if ($index === null) {
            return $this;
        }

        $newName = implode('', self::$namePrefixes) . $name;
        $collection = Kernel::RouteCollection();
        $collection->remove($this->lastNames[$index]);
        $collection->add($newName, $this->lastRoutes[$index]);
        $this->lastNames[$index] = $newName;

        return $this;
    }

    /**
     * Constrain route placeholders with regex requirements (10.7):
     *
     *   Route::get('/users/{id}', ...)->where('id', '\d+');
     *   Route::get('/posts/{year}/{slug}', ...)->where(['year' => '\d{4}', 'slug' => '[a-z-]+']);
     *
     * Stored as Symfony route requirements, so they are enforced by both the
     * live UrlMatcher and the compiled matcher (route:cache) and validated by
     * the route() URL generator. Chainable with name()/middleware().
     *
     * @param string|array<string, string> $param Placeholder name, or a map of name => regex.
     * @param string|null $regex Required when $param is a string.
     * @return static
     */
    public function where(string|array $param, ?string $regex = null): static
    {
        if (is_string($param)) {
            if ($regex === null) {
                throw new InvalidArgumentException("where('{$param}') needs a regex: where('{$param}', '\\d+') or where(['{$param}' => '\\d+']).");
            }
            $param = [$param => $regex];
        }

        foreach ($this->lastRoutes as $r) {
            $r->addRequirements($param);
        }
        return $this;
    }

    /**
     * Shortcut route: redirect $from to $to (10.7).
     *
     *   Route::redirect('/old', '/new');        // 302
     *   Route::redirect('/old', '/new', 301);   // permanent
     *
     * Cacheable by design: the controller is a framework class string
     * (Ions\Http\RedirectController) and the target/status travel as route
     * DEFAULTS — route:cache compiles both. Registers for ANY method and
     * honours the enclosing prefix() stack. name()/where() chainable.
     *
     * @return static
     */
    public static function redirect(string $from, string $to, int $status = 302): static
    {
        $route = new static();
        $route->newRoute(
            null,
            self::applyPrefix($from),
            \Ions\Http\RedirectController::class . '::handle',
            ['_redirect_to' => $to, '_redirect_status' => $status],
            [],
            []
        );
        return $route;
    }

    /**
     * Shortcut route: render a Twig template for GET $uri (10.7).
     *
     *   Route::view('/welcome', 'pages.welcome', ['who' => 'world']);
     *
     * Cacheable by design: the controller is a framework class string
     * (Ions\Http\ViewController) and the template/data travel as route
     * DEFAULTS (plain scalars/arrays — keep closures out of $data when using
     * route:cache). The template renders through the 9.2 View at hit time.
     * name()/where() chainable.
     *
     * @param string $template Template name — view() helper syntax (dots, '@namespace').
     * @param array<string, mixed> $data Variables passed to the template.
     * @return static
     */
    public static function view(string $uri, string $template, array $data = []): static
    {
        $route = new static();
        $route->newRoute(
            null,
            self::applyPrefix($uri),
            \Ions\Http\ViewController::class . '::handle',
            ['_view_template' => $template, '_view_data' => $data],
            ['GET'],
            []
        );
        return $route;
    }

    /**
     * Register a 404 catch-all for the route group being loaded (10.7).
     *
     *   Route::fallback(fn () => view('errors.404-suggestions'));   // closure
     *   Route::fallback('FallbackController::show');                // cacheable
     *
     * Deferred, not added immediately: Symfony matches in definition order, so
     * the catch-all ('/{fallback}' with a '.*' requirement, GET) must come
     * LAST. Kernel::buildRouteCollection() consumes it after the routes file,
     * attribute routes and built-ins are loaded — real routes always win.
     * Declare it in routes/web.php or routes/api.php; it applies to the group
     * whose file registered it. A Closure handler works live but, like any
     * closure route, makes route:cache refuse — use a controller string when
     * caching routes.
     */
    public static function fallback(callable|string $handler): void
    {
        self::$fallback = is_string($handler) ? $handler : Closure::fromCallable($handler);
    }

    /**
     * Hand the deferred fallback handler to the kernel and clear it.
     *
     * Called by Kernel::buildRouteCollection() at the end of each group build;
     * consuming (not peeking) scopes the fallback to the group whose routes
     * file registered it.
     *
     * @internal
     */
    public static function consumeFallback(): Closure|string|null
    {
        $handler = self::$fallback;
        self::$fallback = null;

        return $handler;
    }

    /**
     * Clear all static registration state (prefix/name/middleware stacks and
     * the deferred fallback). Called by Kernel::resetForTesting() so a test
     * that aborts mid-group can never leak state into the next boot.
     *
     * @internal
     */
    public static function resetForTesting(): void
    {
        self::$prefix = [];
        self::$controls = [];
        self::$namePrefixes = [];
        self::$groupMiddleware = [];
        self::$fallback = null;
    }

    private function inRoute($method, $path, $controller, $defaults = [], $name = null, $wheres = []): void
    {
        // Controller-namespace prefix (2nd arg of prefix()) only applies to
        // string controllers: the stack holds nulls for prefix-only groups,
        // and concatenating onto a Closure is meaningless (and fatal).
        $controls = implode("", array_filter(self::$controls, static fn ($c) => $c !== null));

        $this->route_details = [
            'method' => $method,
            'path' => self::applyPrefix($path),
            'controller' => ($controls !== '' && is_string($controller)) ? $controls . $controller : $controller,
            'defaults' => $defaults,
            'name' => $name,
            'wheres' => $wheres
        ];
    }

    /**
     * Prepend the current prefix() stack to a path.
     */
    private static function applyPrefix(string $path): string
    {
        return !empty(self::$prefix) ? implode("", self::$prefix) . $path : $path;
    }

    private function _prefix(string $prefix, $controls = null, ?Closure $closure = null)
    {
        self::$prefix[] = $prefix;
        self::$controls[] = $controls;

        if ($closure instanceof Closure) {
            // finally: a throwing group closure must never leak its prefix
            // onto subsequently registered routes (worker re-registration,
            // try/catch'd conditional registration in App\Booting).
            try {
                $closure();
            } finally {
                array_pop(self::$prefix);
                array_pop(self::$controls);
            }
        } else {
            // Deferred form: ...->group() pops. Until then this instance is a
            // group builder — name()/middleware() collect group attributes.
            $this->groupBuilder = true;
        }
    }

    private function _group(Closure $closure): void
    {
        $pushedName = $this->pendingNamePrefix !== '';
        if ($pushedName) {
            self::$namePrefixes[] = $this->pendingNamePrefix;
        }
        $pushedMiddleware = $this->pendingMiddleware !== [];
        if ($pushedMiddleware) {
            self::$groupMiddleware[] = $this->pendingMiddleware;
        }

        // finally: a throwing group closure must never leak group state.
        try {
            $closure();
        } finally {
            if ($pushedName) {
                array_pop(self::$namePrefixes);
            }
            if ($pushedMiddleware) {
                array_pop(self::$groupMiddleware);
            }
            array_pop(self::$prefix);
            array_pop(self::$controls);
        }
    }

    private function newRoute($name, $path, $controller, $defaults, $method, $wheres): void
    {
        if (is_null($name)) {
            $name = Str::random(10);
        } elseif (self::$namePrefixes !== []) {
            // Explicitly named routes inside a name('admin.')-prefixed group
            // get the stacked prefix; auto-random names stay as-is.
            $name = implode('', self::$namePrefixes) . $name;
        }

        $sroute = new SRoute(
            path: $path,
            defaults: ['_controller' => $controller] + $defaults,
            requirements: $wheres,
            options: [],
            host: '',
            schemes: [],
            methods: $method
        );

        if (self::$groupMiddleware !== []) {
            $sroute->setOption('middleware', array_values(array_unique(array_merge(...self::$groupMiddleware))));
        }

        Kernel::RouteCollection()->add($name, $sroute);

        $this->lastRoutes[] = $sroute;
        $this->lastNames[] = $name;
    }

    public function _resource(string $name, string $controller): void
    {
        $this->newRoute($name . '.index', '/' . $name, $controller . '@index', [], ['GET'], []);
        $this->newRoute($name . '.create', '/' . $name . '/create', $controller . '@create', [], ['GET'], []);
        $this->newRoute($name . '.store', '/' . $name, $controller . '@store', [], ['POST'], []);
        $this->newRoute($name . '.show', '/' . $name . '/{id}', $controller . '@show', [], ['GET'], []);
        $this->newRoute($name . '.edit', '/' . $name . '/{id}/edit', $controller . '@edit', [], ['GET'], []);
        $this->newRoute($name . '.update', '/' . $name . '/{id}', $controller . '@update', [], ['PUT'], []);
        $this->newRoute($name . '.destroy', '/' . $name . '/{id}', $controller . '@destroy', [], ['DELETE'], []);
    }
}
