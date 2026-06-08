<?php

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Foundation\Kernel;

final class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('jwt')) {
            // singleton; may resolve to null when APP_KEY is missing/short (auth then 401s)
            $this->container->singleton('jwt', static fn () => Kernel::buildJwt());
        }
    }
}
