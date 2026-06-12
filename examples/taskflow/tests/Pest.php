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
// Each test boots a fresh kernel; force the DB onto a throwaway in-memory
// SQLite so the suite never touches database/database.sqlite and every test
// starts with empty tables. Tests that need schema call migrateTaskflow().
$_ENV['DB_CONNECTION'] = $_ENV['DB_CONNECTION'] ?? 'sqlite';
$_ENV['DB_DATABASE'] = $_ENV['DB_DATABASE'] ?? ':memory:';

/*
|--------------------------------------------------------------------------
| migrateTaskflow()
|--------------------------------------------------------------------------
| Run the host's real migration files (database/schemas/*.php) against the
| booted connection. Each schema file `return new class extends Migration`,
| exactly what `ions migrate` discovers and runs — so exercising them here
| proves the migrations themselves. Call it from a test's beforeEach() (after
| the kernel has booted) before touching any model.
*/
function migrateTaskflow(): void
{
    $schemas = glob(dirname(__DIR__) . '/database/schemas/*.php');

    foreach ($schemas ?: [] as $file) {
        $migration = require $file;
        $migration->up();
    }
}
