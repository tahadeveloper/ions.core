<?php

declare(strict_types=1);

return [
    // 'sync' runs jobs inline; switch to 'database' (+ a jobs table and a
    // `php bin/ions queue:work` worker) for real background processing.
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ],
];
