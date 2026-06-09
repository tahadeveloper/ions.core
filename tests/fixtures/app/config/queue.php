<?php

return [
    // Default connection. Tests use 'sync' so jobs run inline by default.
    'default' => 'sync',

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'      => 'database',
            'table'       => 'jobs',
            'queue'       => 'default',
            'retry_after' => 90,
        ],
    ],
];
