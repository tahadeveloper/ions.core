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

        // Interface binding consumed by the 9.3 controller DI fixtures
        // (constructor + action injection of a provider-bound abstraction).
        $this->container->bind(
            \IonsFixture\Services\GreeterContract::class,
            \IonsFixture\Services\SimpleGreeter::class,
        );
    }

    public function boot(): void
    {
        $this->container->instance('fixture.auto.booted', true);
    }
}
