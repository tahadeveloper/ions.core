<?php

declare(strict_types=1);

return [
    // Default disk resolved by the FilesystemManager / Storage helper.
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // BC: IonDisk/IonUpload read filesystem.disks.default for their adapter.
        'default' => env('FILESYSTEM_DISK', 'local'),

        'local' => [
            'driver' => 'local',
            'root' => \Ions\Bundles\Path::public('uploads'),
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
