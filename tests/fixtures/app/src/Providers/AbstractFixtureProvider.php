<?php

declare(strict_types=1);

namespace IonsFixture\Providers;

use Ions\Container\ServiceProvider;

/**
 * Abstract provider fixture: discovery must SKIP abstract classes.
 */
abstract class AbstractFixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('fixture.abstract.marker', 'should-never-bind');
    }
}
