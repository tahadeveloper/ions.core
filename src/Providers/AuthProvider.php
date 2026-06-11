<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Auth\Gate;
use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Auth\Providers\SentinelUserProvider;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\RateLimitMiddleware;
use Ions\Security\CacheRevocationStore;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the default file-cache-backed revocation store if none is already registered.
        // Revocations persist across requests via a file cache at var/cache/revocations.
        // Apps that need distributed revocation (e.g. Redis) should bind their own
        // RevocationStore implementation as 'revocation_store' BEFORE AuthProvider runs.
        if (!$this->container->bound('revocation_store')) {
            $container = $this->container;
            $this->container->singleton('revocation_store', static function () use ($container) {
                // Reuse the shared cache manager so the framework has a single
                // cache configuration. The 'file' store keeps revocations
                // persistent across requests (matching the previous behaviour);
                // hosts can point cache.stores.file at redis/etc. as needed.
                /** @var \Illuminate\Cache\CacheManager $cache */
                $cache = $container->get('cache');

                return new CacheRevocationStore($cache->store(self::persistentStore()));
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

        // Authorization gate (10.4): lazy singleton so abilities/policies
        // defined by host providers (conventionally an auto-discovered
        // app/Providers/AuthServiceProvider) accumulate on ONE instance.
        // The class alias lets controllers/actions type-hint Ions\Auth\Gate.
        if (!$this->container->bound('gate')) {
            $container = $this->container;
            $this->container->singleton('gate', static fn (): Gate => new Gate($container));
            $this->container->alias('gate', Gate::class);
        }

        if (!$this->container->bound('csrf')) {
            $container = $this->container;
            $this->container->singleton('csrf', static function () use ($container): CsrfTokenManager {
                // Prefer the framework session (single source of truth) via the
                // shared RequestStack bound by SessionProvider. Fall back to the
                // native PHP session when no session subsystem is registered.
                if ($container->bound('request_stack')) {
                    /** @var RequestStack $stack */
                    $stack = $container->get('request_stack');
                    $storage = new SessionTokenStorage($stack);
                } else {
                    $storage = new NativeSessionTokenStorage();
                }

                return new CsrfTokenManager(new UriSafeTokenGenerator(), $storage);
            });
        }

        // Bind RateLimitMiddleware so container->make(RateLimitMiddleware::class) works
        // from resolveMiddleware() when the 'throttle' alias (or the FQCN) is used on a route.
        // Limits are read from config('app.ratelimit.*') with sensible defaults.
        if (!$this->container->bound(RateLimitMiddleware::class)) {
            $container = $this->container;
            $this->container->singleton(RateLimitMiddleware::class, static function () use ($container) {
                // Throttle counters live in the shared cache (persistent store).
                /** @var \Illuminate\Cache\CacheManager $cache */
                $cache = $container->get('cache');

                return new RateLimitMiddleware(
                    $cache->store(self::persistentStore()),
                    (int) config('app.ratelimit.max', 60),
                    (int) config('app.ratelimit.decay', 60),
                );
            });
        }
    }

    /**
     * The cache store used for data that must survive across requests
     * (revocations, throttle counters). Hosts may override it via
     * config('cache.persistent_store'); defaults to the 'file' store, falling
     * back to whatever the cache default is when 'file' is not configured.
     */
    private static function persistentStore(): string
    {
        $store = config('cache.persistent_store');
        if (is_string($store) && $store !== '') {
            return $store;
        }

        /** @var array<string,mixed> $stores */
        $stores = (array) config('cache.stores', []);

        return isset($stores['file']) ? 'file' : (string) config('cache.default', 'file');
    }
}
