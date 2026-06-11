<?php

declare(strict_types=1);

namespace IonsFixture\Middleware;

use Ions\Http\Middleware\Psr15Adapter;

/** Subclass-alias for the short-circuit PSR-15 fixture (see FixturePsr15Alias). */
final class FixturePsr15ShortCircuitAlias extends Psr15Adapter
{
    public function __construct()
    {
        parent::__construct(new FixturePsr15ShortCircuit());
    }
}
