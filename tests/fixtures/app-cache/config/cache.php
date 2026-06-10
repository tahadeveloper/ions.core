<?php

return [
    // Use the in-memory array store so no filesystem is touched during tests.
    'default' => 'array',
    'prefix' => 'ions_cache_test',

    // The persistent store (throttle counters, revocations) also stays in memory.
    'persistent_store' => 'array',

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
];
