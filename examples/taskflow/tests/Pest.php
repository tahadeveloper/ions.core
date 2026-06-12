<?php

declare(strict_types=1);

use Tests\TaskflowTestCase;

/*
|--------------------------------------------------------------------------
| Taskflow test bootstrap
|--------------------------------------------------------------------------
| Every test extends Ions\Testing\TestCase, which boots a fresh kernel from
| $basePath (the example root) in setUp() and resets all framework static
| state in tearDown(). $_ENV / $_SERVER are snapshotted around each test by
| the base TestCase, so nothing leaks between tests.
|
| No real .env is committed. We materialise one from .env.example for the
| duration of the run (register_shutdown_function removes it) so the kernel's
| Dotenv::safeLoad() never emits a "missing file" warning — the suite must
| stay warning-clean. SESSION_DRIVER=array (no native CLI session),
| APP_DEBUG=true (loud boot failures) and a throwaway APP_KEY are forced into
| $_ENV here; Dotenv::createImmutable does not overwrite pre-set values, and
| the base TestCase restores $_ENV afterwards.
*/

uses(TaskflowTestCase::class)->in(__DIR__);

$root = dirname(__DIR__);

if (!is_file($root . '/.env')) {
    copy($root . '/.env.example', $root . '/.env');
    register_shutdown_function(static function () use ($root): void {
        @unlink($root . '/.env');
    });
}

$_ENV['SESSION_DRIVER'] = $_ENV['SESSION_DRIVER'] ?? 'array';
$_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? 'true';
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? str_repeat('a', 64);
