<?php

declare(strict_types=1);

return [
    // Channel used by Log::info()/Log::error()/… when none is named.
    'default' => env('LOG_CHANNEL', 'app'),

    // Every channel gets secret redaction (password/token/secret/… context
    // values masked) and a per-request `request_id` in the record extra,
    // shared across all channels within one request.
    'channels' => [
        // Single file under var/logs (relative paths resolve there;
        // absolute paths are kept as-is).
        'app' => [
            'driver' => 'single',
            'path' => 'app.log',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        // Daily rotation: var/logs/app-YYYY-MM-DD.log, pruned after 'days'.
        // 'daily' => [
        //     'driver' => 'daily',
        //     'path'   => 'app.log',
        //     'days'   => 14,
        //     'level'  => 'debug',
        // ],

        // Process stderr — the natural sink for containers / worker mode.
        // 'stderr' => [
        //     'driver' => 'stderr',
        //     'level'  => 'warning',
        // ],

        // Fan-out: one write lands on every member channel (each member
        // keeps its own level threshold).
        // 'stack' => [
        //     'driver'   => 'stack',
        //     'channels' => ['app', 'stderr'],
        // ],
    ],
];
