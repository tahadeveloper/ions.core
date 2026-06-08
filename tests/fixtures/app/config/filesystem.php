<?php

return [
    'disks' => [
        'default' => 'local',
        'local' => [
            'root' => sys_get_temp_dir(),
        ],
        's3' => [
            'bucket' => null,
            'region' => null,
            'version' => 'latest',
            'key' => null,
            'secret' => null,
        ],
    ],
];
