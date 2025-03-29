<?php

namespace Ions\Support;

use Symfony\Component\Routing\Annotation\Route as BaseRoute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route extends BaseRoute
{
    public string $path;

    public function __construct(
        string $path,
        string $name = null,
        array  $requirements = [],
        array  $options = [],
        string $host = '',
        array  $methods = [],
        array  $schemes = [],
        string $condition = '',
        array  $defaults = [],
        int    $priority = 0,
        string $env = null
    )
    {
        $this->path = $path;
        parent::__construct(
            path: $path,
            name: $name,
            requirements: $requirements,
            options: $options,
            host: $host,
            methods: $methods,
            schemes: $schemes,
            condition: $condition,
            defaults: $defaults,
            priority: $priority,
            env: $env
        );
    }
}