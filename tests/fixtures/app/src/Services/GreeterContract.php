<?php

declare(strict_types=1);

namespace IonsFixture\Services;

/**
 * Interface bound to SimpleGreeter by FixtureAutoProvider — proves that
 * constructor/action injection resolves provider-bound abstractions, not
 * just auto-wirable concretes.
 */
interface GreeterContract
{
    public function greet(): string;
}
