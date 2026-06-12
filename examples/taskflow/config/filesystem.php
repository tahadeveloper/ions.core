<?php

declare(strict_types=1);

return [
    // Default disk resolved by the FilesystemManager / Storage helper.
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // BC: IonDisk/IonUpload read filesystem.disks.default for their adapter.
        'default' => env('FILESYSTEM_DISK', 'local'),

        // The default 'local' disk is PRIVATE: it roots under var/storage —
        // OUTSIDE the public web root — so task attachments written here are
        // NOT directly fetchable by URL. They are served only through the
        // authorized TaskController download action (which streams them).
        'local' => [
            'driver' => 'local',
            'root' => \Ions\Bundles\Path::var('storage'),
        ],

        's3' => [
            'driver' => 's3',
            'bucket' => env('AWS_BUCKET'),
            'region' => env('AWS_DEFAULT_REGION'),
            'version' => 'latest',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
    ],
];
