<?php

declare(strict_types=1);

namespace Ions\Support;

use Symfony\Component\Routing\Attribute\Route as BaseRoute;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route extends BaseRoute
{
    public function __construct(
        string|array|null $path = null,
        ?string           $name = null,
        array             $requirements = [],
        array             $options = [],
        array             $defaults = [],
        ?string           $host = null,
        array|string      $methods = [],
        array|string      $schemes = [],
        ?string           $condition = null,
        ?int              $priority = null,
        string|array|null $env = null,
    ) {
        parent::__construct(
            path: $path,
            name: $name,
            requirements: $requirements,
            options: $options,
            defaults: $defaults,
            host: $host,
            methods: $methods,
            schemes: $schemes,
            condition: $condition,
            priority: $priority,
            env: $env,
        );
    }
}
