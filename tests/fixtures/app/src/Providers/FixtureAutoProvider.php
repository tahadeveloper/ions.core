<?php

declare(strict_types=1);

namespace IonsFixture\Providers;

use Ions\Container\ServiceProvider;

/**
 * Host-app provider fixture used by the discovery tests: it is NOT listed in
 * any config — it must be picked up purely by the {src|app}/Providers scan.
 */
class FixtureAutoProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('fixture.auto.marker', 'auto-discovered');
    }

    public function boot(): void
    {
        $this->container->instance('fixture.auto.booted', true);
    }
}
