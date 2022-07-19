<?php

namespace Ions\Bundles;

use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Support\Str;
use Closure;
use Symfony\Component\Routing\Route as SRoute;

/**
 * Class Route
 * @package Ions\Bundles
 * @method static Route get(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route post(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route put(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route delete(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route patch(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route options(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route any(string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route match(array $methods, string $path, string $controller, array $defaults = [], ?string $name = null, array $wheres = []): static
 * @method static Route prefix(string $name, ?string $controls = null, ?Closure $closure = null): static
 * @method Route group(Closure $closure): void
 */
class Route extends Singleton
{
    private static array $prefix = [];
    private static array $controls = [];
    private array $route_details;

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
        } else {
            abort(500, 'Method not found');
        }
        return $route;
    }

    public function __call(string $name, array $arguments)
    {
        $route = new self();
        if ($name == 'group') {
            $route->_group(...$arguments);
        } else {
            abort(500, 'Method not found');
        }
    }

    private function inRoute($method, $path, $controller, $defaults = [], $name = null, $wheres = []): void
    {
        $this->route_details = [
            'method' => $method,
            'path' => !empty(static::$prefix) ? implode("", static::$prefix) . $path : $path,
            'controller' => !empty(static::$controls) ? implode("", static::$controls) . $controller : $controller,
            'defaults' => $defaults,
            'name' => $name,
            'wheres' => $wheres
        ];
    }

    private function _prefix(string $prefix, $controls = null, ?Closure $closure = null)
    {
        static::$prefix[] = $prefix;
        static::$controls[] = $controls;

        if ($closure instanceof Closure) {
            $closure();
            array_pop(static::$prefix);
            array_pop(static::$controls);
        }
    }

    private function _group(Closure $closure): void
    {
        $closure();
        array_pop(static::$controls);
    }

    private function newRoute($name, $path, $controller, $defaults, $method, $wheres): void
    {
        if (is_null($name)) {
            $name = Str::random(10);
        }

        Kernel::RouteCollection()->add($name,
            new SRoute(
                path: $path,
                defaults: ['_controller' => $controller] + $defaults,
                requirements: $wheres,
                options: [],
                host: '',
                schemes: [],
                methods: $method
            )
        );

    }
}

