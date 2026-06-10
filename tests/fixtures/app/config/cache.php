<?php

return [
    // Default store used when cache() is called without a store name.
    // Tests use the in-memory 'array' store so no filesystem is touched.
    'default' => 'array',

    // Global key prefix applied to every store.
    'prefix' => 'ions_test',

    // Store used for cross-request data (JWT revocations, throttle counters).
    // Tests keep it in-memory so no files are written under var/cache.
    'persistent_store' => 'array',

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        // 'file' store lives under var/cache/data by default; resolved lazily.
        'file' => [
            'driver' => 'file',
        ],
    ],
];
