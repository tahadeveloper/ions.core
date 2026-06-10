<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Cache\CacheManager;
use Ions\Bundles\Path;
use Ions\Container\ServiceProvider;

/**
 * Binds the shared Illuminate {@see CacheManager} into the container.
 *
 * The manager is configured from config('cache.*') and resolves its
 * dependencies (config, files, db) straight from the Ions container, which
 * already extends Illuminate's container. Stores are created lazily, so the
 * file/redis drivers are only built when actually used.
 *
 * Other subsystems (the JWT revocation store, the rate limiter) resolve a
 * named store from this manager rather than constructing their own cache —
 * one cache configuration, one source of truth.
 */
final class CacheProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->container->bound('cache')) {
            return;
        }

        // Ensure sane cache config defaults so the manager can construct even
        // when the host ships a minimal config/cache.php (or none at all).
        $this->ensureDefaults();

        $container = $this->container;
        $this->container->singleton('cache', static function () use ($container): CacheManager {
            // CacheManager only consumes its $app via array-access for the
            // 'config', 'files', 'db' and 'redis' bindings — all of which the
            // Ions container (an Illuminate container) already resolves. The
            // constructor's Application type-hint is a docblock-only hint, so we
            // pass the container directly rather than building a 30-method shim.
            /** @phpstan-ignore argument.type */
            return new CacheManager($container);
        });

        // Convenience alias used by repositories that type-hint the store.
        $this->container->bind('cache.store', static fn () => $container->get('cache')->store());
    }

    /**
     * Populate missing cache config keys with framework defaults without
     * overwriting anything the host already configured.
     */
    private function ensureDefaults(): void
    {
        if (config('cache.default') === null) {
            config(['cache.default' => 'file']);
        }

        if (config('cache.prefix') === null) {
            config(['cache.prefix' => 'ions']);
        }

        /** @var array<string,mixed> $stores */
        $stores = (array) config('cache.stores', []);

        if (!isset($stores['array'])) {
            config(['cache.stores.array' => ['driver' => 'array', 'serialize' => false]]);
        }

        if (!isset($stores['file'])) {
            config(['cache.stores.file' => ['driver' => 'file']]);
        }

        // The file store needs a writable path; default it under var/cache/data.
        if (config('cache.stores.file.path') === null) {
            config(['cache.stores.file.path' => Path::cache('data')]);
        }
    }
}
