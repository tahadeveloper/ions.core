<?php

declare(strict_types=1);

return [
    // Default store used by cache(). The 'file' driver writes under
    // var/cache/data and needs no external service.
    'default' => env('CACHE_DRIVER', 'file'),

    // Prefix prepended to every cache key.
    'prefix' => 'ions',

    // Store used for cross-request data (JWT revocations, rate-limit and
    // forgot-password throttle counters). MUST be a persistent driver in
    // production ('file', 'redis', …) — never 'array'.
    'persistent_store' => 'file',

    'stores' => [
        'file' => [
            'driver' => 'file',
            // 'path' defaults to var/cache/data
        ],

        'array' => [
            'driver' => 'array',
        ],

        // Redis (requires a host-bound redis factory / predis):
        // 'redis' => ['driver' => 'redis', 'connection' => 'cache'],
    ],

    // Full-page response cache (12.5). Only consulted on routes carrying the
    // 'cache.response' middleware (opt-in). Bypassed entirely on APP_DEBUG.
    // A tag-capable store (redis/memcached) lets `cache:clear-responses` purge
    // ONLY these entries; on array/file it falls back to a full store flush.
    'response' => [
        'enabled' => true,
        'ttl' => 300,        // store TTL (seconds) when the response sets none
        'max_ttl' => 86400,  // hard ceiling on any requested TTL
        'prefix' => 'response_cache:',
        'tag' => 'ions_response_cache',
    ],
];
