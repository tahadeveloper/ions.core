<?php

// Cache.php
namespace Ions\Bundles;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Redis\RedisManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\MemcachedConnector;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

class Cache implements CacheInterface
{
    protected static ?Repository $cache = null;

    protected static function ensureInitialized(): void
    {
        if (self::$cache === null) {
            self::$cache = self::initializeCache();
        }
    }

    protected static function initializeCache(): Repository
    {
        $storeType = config('cache.default');
        $container = new Container;

        switch ($storeType) {
            case 'file':
                $container['config'] = [
                    'cache.default' => config('cache.default'),
                    'cache.stores.file' => config('cache.stores.file'),
                ];
                $container['files'] = new Filesystem;
                break;
            case 'redis':
                $container['config'] = [
                    'cache.default' => config('cache.default'),
                    'cache.stores.redis' => config('cache.stores.redis'),
                    'cache.prefix' => config('cache.prefix'),
                    'database.redis' => config('database.redis')
                ];
                $container['redis'] = new RedisManager(
                    $container,
                    'predis',
                    $container['config']['database.redis']
                );
                break;
            case 'memcached':
                $container['config'] = [
                    'cache.default' => config('cache.default'),
                    'cache.stores.memcached' => config('cache.stores.memcached'),
                    'cache.prefix' => config('cache.prefix'),
                ];
                $container['memcached.connector'] = new MemcachedConnector();
                break;
            default:
                throw new NotFoundResourceException('Cache store not found.');
        }

        return (new CacheManager($container))->store();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function get(string $key)
    {
        self::ensureInitialized();
        return self::$cache->get($key);
    }

    public static function set(string $key, $value, int $ttl = null): void
    {
        self::ensureInitialized();
        self::$cache->put($key, $value, $ttl);
    }

    public static function delete(string $key): void
    {
        self::ensureInitialized();
        self::$cache->forget($key);
    }

    public static function clear(): void
    {
        self::ensureInitialized();
        self::$cache->flush();
    }

    public static function remember($key, $ttl, $callback)
    {
        self::ensureInitialized();
        return self::$cache->remember($key, $ttl, $callback);
    }

    public static function app(): Cache
    {
        return new self();
    }
}
