<?php

namespace Ions\Providers;

use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Auth\Providers\SentinelUserProvider;
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

        if (!$this->container->bound('user_provider')) {
            $this->container->singleton('user_provider', function () {
                $driver = (string) config('auth.provider', 'sentinel');

                return match ($driver) {
                    'eloquent' => new EloquentUserProvider(),
                    'sentinel' => new SentinelUserProvider(),
                    default    => class_exists($driver)
                        ? $this->container->make($driver)
                        : new SentinelUserProvider(),
                };
            });
        }
    }
}
