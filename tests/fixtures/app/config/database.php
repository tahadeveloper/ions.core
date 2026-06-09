<?php

// When IONS_TEST_MYSQL=1 the test-suite targets a real MySQL server (CI only).
// Locally, IONS_TEST_MYSQL is unset and we fall back to the fast in-memory SQLite connection.
if (getenv('IONS_TEST_MYSQL')) {
    return [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'driver'    => 'mysql',
                'host'      => getenv('MYSQL_HOST') ?: '127.0.0.1',
                'port'      => getenv('MYSQL_PORT') ?: '3306',
                'database'  => getenv('MYSQL_DATABASE') ?: 'ions_test',
                'username'  => getenv('MYSQL_USERNAME') ?: 'root',
                'password'  => getenv('MYSQL_PASSWORD') ?: '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
            ],
        ],
    ];
}

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
