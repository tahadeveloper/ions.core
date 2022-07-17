<?php

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Ions\Support\Str;
use Closure;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class MRoute extends Singleton
{
    public static RouteCollection $collection;
    private static string $prefix;
    private static ?string $name;
    private static array $where = [];

    private static ?string $path;
    private static mixed $action;
    private static array $defaults;
    private static mixed $methods;

    public static function _initState(): void
    {
        static::$collection = new RouteCollection();
    }

    public static function get($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'get';

        return new self();
    }

    public static function post($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'post';

        return new self();
    }

    public static function put($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'put';

        return new self();
    }

    public static function patch($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'patch';

        return new self();
    }

    public static function options($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'options';

        return new self();
    }

    public static function delete($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = 'delete';

        return new self();
    }

    public static function match(string|array $methods, $path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = $methods;

        return new self();
    }

    public static function any($path, $action, $other_defaults = []): static
    {
        static::$path = self::checkPrefix() . $path;
        static::$action = $action;
        static::$defaults = $other_defaults;
        static::$methods = [];

        return new self();
    }

    public function save(): void
    {
        self::route(self::checkName(), static::$path, static::$action, static::$defaults, self::checkWhere(), static::$methods);
        static::$path = null;
        static::$action = null;
        static::$defaults = [];
        static::$methods = null;
        static::$name = null;
        static::$where = [];
    }

    public static function prefix(string $prefix): static
    {
        if (isset(static::$prefix)) {
            static::$prefix = static::$prefix . $prefix;
            return new self();
        }

        static::$prefix = $prefix;
        return new self();
    }

    public function group(Closure $action): void
    {
        $action();
        self::$prefix = '';
    }

    public function name(string $name): static
    {
        static::$name = $name;
        return new self();

    }

    public function where(array $requirement): static
    {
        static::$where = $requirement;
        return new self();
    }

    private static function checkPrefix(): ?string
    {
        $prefix = null;
        if (!empty(static::$prefix)) {
            //. DIRECTORY_SEPARATOR
            $prefix = static::$prefix;
        }
        return $prefix;
    }

    private static function checkName(): string
    {
        $name = Str::random(10);
        if (!empty(static::$name)) {
            $name = static::$name;
            static::$name = null;
        }
        return $name;
    }

    private static function checkWhere(): array
    {
        $where = [];
        if (!empty(static::$where)) {
            $where = static::$where;
            static::$where = [];
        }
        return $where;
    }

    private static function route($name, $path, $action, $defaults, $where, $method): void
    {
        static::$collection->add($name,
            new Route(path: $path, defaults: ['_controller' => $action] + $defaults, requirements: $where, options: [], host: '', schemes: [], methods: $method)
        );
    }

}

MRoute::_initState();