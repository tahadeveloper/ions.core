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
];
