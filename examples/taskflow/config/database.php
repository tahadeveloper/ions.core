<?php

declare(strict_types=1);

return [
    // Taskflow defaults to a file-backed SQLite database so it runs with no
    // external service (and its CI job is sqlite-only). Switch to mysql by
    // setting DB_CONNECTION=mysql plus the DB_* credentials in .env.
    'default' => env('DB_CONNECTION', 'sqlite'),

    // Query logging is opt-in (4.1): it buffers every statement in memory,
    // so enable it only while debugging. 'query_log' => true,

    // With APP_DEBUG + query_log on, the N+1 detector warns in
    // var/logs/performance.log when one SELECT pattern repeats >= threshold
    // times per request (see docs/performance.md). Tune or disable with:
    // 'nplusone' => ['enabled' => true, 'threshold' => 5],

    // ORM strict mode (10.6) follows APP_DEBUG: in debug, lazy relation
    // loads and silently discarded fills THROW (Eloquent preventLazyLoading /
    // preventSilentlyDiscardingAttributes); production is always relaxed.
    // Opt out while debugging with: 'strict' => false,

    'connections' => [
        // File-backed SQLite (the example default) — database/database.sqlite.
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', \Ions\Bundles\Path::database('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'taskflow'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
    ],
];
