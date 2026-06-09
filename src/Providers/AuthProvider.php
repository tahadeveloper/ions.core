<?php

namespace Ions\Providers;

use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Auth\Providers\SentinelUserProvider;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\RateLimitMiddleware;
use Ions\Security\CacheRevocationStore;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;

final class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the default file-cache-backed revocation store if none is already registered.
        // Revocations persist across requests via a file cache at var/cache/revocations.
        // Apps that need distributed revocation (e.g. Redis) should bind their own
        // RevocationStore implementation as 'revocation_store' BEFORE AuthProvider runs.
        if (!$this->container->bound('revocation_store')) {
            $this->container->singleton('revocation_store', static function () {
                $dir = \Ions\Bundles\Path::cache('revocations');
                $store = new \Illuminate\Cache\FileStore(new \Illuminate\Filesystem\Filesystem(), $dir);
                return new CacheRevocationStore(new \Illuminate\Cache\Repository($store));
            });
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

        // Bind RateLimitMiddleware so container->make(RateLimitMiddleware::class) works
        // from resolveMiddleware() when the 'throttle' alias (or the FQCN) is used on a route.
        // Limits are read from config('app.ratelimit.*') with sensible defaults.
        if (!$this->container->bound(RateLimitMiddleware::class)) {
            $this->container->singleton(RateLimitMiddleware::class, static function () {
                $dir = \Ions\Bundles\Path::cache('ratelimit');
                $store = new \Illuminate\Cache\FileStore(new \Illuminate\Filesystem\Filesystem(), $dir);
                $cacheRepo = new \Illuminate\Cache\Repository($store);

                return new RateLimitMiddleware(
                    $cacheRepo,
                    (int) config('app.ratelimit.max', 60),
                    (int) config('app.ratelimit.decay', 60),
                );
            });
        }
    }
}
