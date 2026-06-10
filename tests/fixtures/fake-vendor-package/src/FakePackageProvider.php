<?php

declare(strict_types=1);

namespace Acme\FakePackage;

use Ions\Container\ServiceProvider;

/**
 * Provider shipped by the fake third-party package fixture. Declared in the
 * package's composer.json under extra.ions.providers — composer-extra
 * discovery must register it with zero host configuration.
 */
class FakePackageProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('fake.package.marker', 'package-discovered');
    }

    public function boot(): void
    {
        $this->container->instance('fake.package.booted', true);
    }
}
