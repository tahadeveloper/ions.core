<?php

namespace Ions\Bundles;

interface CacheInterface
{
    public static function get(string $key);

    public static function set(string $key, $value, int $ttl = null);

    public static function delete(string $key);

    public static function clear();
}
