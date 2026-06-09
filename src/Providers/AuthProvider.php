<?php

namespace Ions\Providers;

use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Auth\Providers\SentinelUserProvider;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Kernel;
use Ions\Security\ArrayRevocationStore;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;

final class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the default in-memory revocation store if none is already registered.
        // Apps that need cross-request revocation should bind a cache-backed
        // RevocationStore implementation as 'revocation_store' BEFORE AuthProvider runs.
        if (!$this->container->bound('revocation_store')) {
            $this->container->singleton('revocation_store', static fn () => new ArrayRevocationStore());
        }

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

        if (!$this->container->bound('csrf')) {
            $this->container->singleton('csrf', static fn () => new CsrfTokenManager(
                new UriSafeTokenGenerator(),
                new NativeSessionTokenStorage()
            ));
        }
    }
}
