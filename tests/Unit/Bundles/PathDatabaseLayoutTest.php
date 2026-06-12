<?php

use Ions\Bundles\Path;

/**
 * Host-root database/ layout (Phase 11.1): since 4.4 `Path::database()`
 * resolves `{root}/database` FIRST whenever that directory exists, falling
 * back byte-identically to the legacy `{app|src}/Database` resolution
 * otherwise. On the NEW layout the legacy subfolder names the framework
 * commands pass in ('Schema', 'Migrations', 'migrations', 'Seeders') are
 * normalized onto the lowercase standard directories (schemas/, migrations/,
 * seeders/, factories/, backups/); the legacy path keeps the exact names so
 * existing hosts are untouched.
 */

/**
 * Create a throwaway host root containing the given directories and return
 * its path. Callers clean up via removeDatabaseLayoutHost().
 *
 * @param list<string> $dirs
 */
function makeDatabaseLayoutHost(array $dirs): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ions-path-db-layout-' . bin2hex(random_bytes(4));

    mkdir($base, 0777, true);

    foreach ($dirs as $dir) {
        mkdir($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir), 0777, true);
    }

    return $base;
}

/** @param list<string> $dirs */
function removeDatabaseLayoutHost(string $base, array $dirs): void
{
    foreach ($dirs as $dir) {
        // Remove the dir and every now-empty ancestor up to (not including) $base.
        $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        while (strlen($path) > strlen($base)) {
            @rmdir($path);
            $path = dirname($path);
        }
    }
    @rmdir($base);
}

afterEach(function () {
    Path::resetBasePath();
});

test('Path::database() prefers host-root database/ when both layouts exist', function () {
    $dirs = ['database', 'app/Database'];
    $base = makeDatabaseLayoutHost($dirs);

    try {
        Path::setBasePath($base);

        expect(Path::database('Schema'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schemas');
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }
});

test('Path::database() maps the legacy subfolder names onto the lowercase standard on the new layout', function () {
    $dirs = ['database'];
    $base = makeDatabaseLayoutHost($dirs);
    $root = $base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;

    try {
        Path::setBasePath($base);

        // The EXACT names the framework commands pass in today (4.6 layout):
        // SchemaCommand/MigrateCommand/DumpCommand --prune → 'migrations',
        // DumpCommand dump target → 'schemas/...', RollBackCommand → 'schemas/...',
        // SeederCommand → 'Seeders/...'. The map normalizes casing only.
        expect(Path::database('Schema'))->toBe($root . 'schemas')
            ->and(Path::database('Schema/_123_users.php'))->toBe($root . 'schemas' . DIRECTORY_SEPARATOR . '_123_users.php')
            ->and(Path::database('Migrations/2026_01_01_000000_default_schema.dump'))->toBe($root . 'migrations' . DIRECTORY_SEPARATOR . '2026_01_01_000000_default_schema.dump')
            ->and(Path::database('migrations/2026_01_01_000000_default_schema.dump'))->toBe($root . 'migrations' . DIRECTORY_SEPARATOR . '2026_01_01_000000_default_schema.dump')
            ->and(Path::database('Seeders/UserSeeder.php'))->toBe($root . 'seeders' . DIRECTORY_SEPARATOR . 'UserSeeder.php');
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }
});

test('Path::database() passes modern lowercase and unknown names through unchanged on the new layout', function () {
    $dirs = ['database'];
    $base = makeDatabaseLayoutHost($dirs);
    $root = $base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;

    try {
        Path::setBasePath($base);

        expect(Path::database('seeders/UserSeeder.php'))->toBe($root . 'seeders' . DIRECTORY_SEPARATOR . 'UserSeeder.php')
            ->and(Path::database('schemas'))->toBe($root . 'schemas')
            ->and(Path::database('factories/UserFactory.php'))->toBe($root . 'factories' . DIRECTORY_SEPARATOR . 'UserFactory.php')
            ->and(Path::database('backups/2026.sql'))->toBe($root . 'backups' . DIRECTORY_SEPARATOR . '2026.sql')
            ->and(Path::database('database.sqlite'))->toBe($root . 'database.sqlite');
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }
});

test('Path::database() legacy resolution is byte-identical when host-root database/ is absent', function () {
    // app/-layout host: exact legacy names preserved, no normalization.
    $dirs = ['app'];
    $base = makeDatabaseLayoutHost($dirs);

    try {
        Path::setBasePath($base);

        expect(Path::database('Schema'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Schema')
            ->and(Path::database('Migrations/x.dump'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations/x.dump');
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }

    // src/-layout host: the legacy fallback chain still applies untouched.
    $dirs = ['src'];
    $base = makeDatabaseLayoutHost($dirs);

    try {
        Path::setBasePath($base);

        expect(Path::database('Seeders/UserSeeder.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders/UserSeeder.php');
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }
});

test('Path::database() with no sub-path returns the new-layout root', function () {
    $dirs = ['database'];
    $base = makeDatabaseLayoutHost($dirs);

    try {
        Path::setBasePath($base);

        expect(Path::database())
            ->toBe($base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR);
    } finally {
        removeDatabaseLayoutHost($base, $dirs);
    }
});
