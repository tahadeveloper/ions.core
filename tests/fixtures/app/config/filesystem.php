<?php

return [
    // Default disk resolved by the FilesystemManager / Storage helper.
    'default' => 'local',

    'disks' => [
        // BC: IonDisk reads filesystem.disks.default to pick its adapter.
        'default' => 'local',

        'local' => [
            'driver' => 'local',
            'root' => sys_get_temp_dir(),
        ],

        'memory' => [
            'driver' => 'memory',
        ],

        's3' => [
            'driver' => 's3',
            'bucket' => null,
            'region' => null,
            'version' => 'latest',
            'key' => null,
            'secret' => null,
        ],
    ],
];
