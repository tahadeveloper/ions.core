<?php

declare(strict_types=1);

namespace IonsFixture\Middleware;

use Ions\Http\Middleware\Psr15Adapter;

/**
 * The documented host pattern for using a PSR-15 middleware in
 * middleware_aliases (10.8): aliases map to class-strings that the container
 * instantiates with no arguments, so hosts subclass Psr15Adapter and pin the
 * wrapped PSR-15 middleware in their constructor.
 */
final class FixturePsr15Alias extends Psr15Adapter
{
    public function __construct()
    {
        parent::__construct(FixturePsr15Middleware::class);
    }
}
