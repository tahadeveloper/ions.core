<?php

use Ions\Bundles\Path;

/**
 * Host-layout precedence (Phase 9.1): `app/` is the convention since 4.2 —
 * Path::src()/api()/database() resolve {root}/app FIRST and fall back to
 * {root}/src for hosts still on the legacy layout. The fallback is preserved
 * verbatim so existing src/ hosts keep working untouched.
 */

/**
 * Create a throwaway host root containing the given layout dirs and return
 * its path. Callers clean up via removeLayoutHost().
 *
 * @param list<string> $dirs
 */
function makeLayoutHost(array $dirs): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ions-path-layout-' . bin2hex(random_bytes(4));

    foreach ($dirs as $dir) {
        mkdir($base . DIRECTORY_SEPARATOR . $dir, 0777, true);
    }

    return $base;
}

/** @param list<string> $dirs */
function removeLayoutHost(string $base, array $dirs): void
{
    foreach ($dirs as $dir) {
        @rmdir($base . DIRECTORY_SEPARATOR . $dir);
    }
    @rmdir($base);
}

afterEach(function () {
    Path::resetBasePath();
});

test('Path::src() prefers app/ when both app/ and src/ exist', function () {
    $base = makeLayoutHost(['app', 'src']);

    try {
        Path::setBasePath($base);

        expect(Path::src('Jobs/SendReport.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Jobs/SendReport.php');
    } finally {
        removeLayoutHost($base, ['app', 'src']);
    }
});

test('Path::api() and Path::database() follow the app/-first precedence', function () {
    $base = makeLayoutHost(['app', 'src']);

    try {
        Path::setBasePath($base);

        expect(Path::api('PingController.php'))
            ->toContain(DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR)
            ->and(Path::api('PingController.php'))->toContain('Http' . DIRECTORY_SEPARATOR . 'Api')
            ->and(Path::database('Migrations/x.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Migrations/x.php');
    } finally {
        removeLayoutHost($base, ['app', 'src']);
    }
});

test('Path::src() falls back to src/ when only src/ exists (legacy layout)', function () {
    $base = makeLayoutHost(['src']);

    try {
        Path::setBasePath($base);

        expect(Path::src('Models/User.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models/User.php')
            ->and(Path::database('Seeders/UserSeeder.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Seeders/UserSeeder.php')
            ->and(Path::api(''))->toContain(DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR);
    } finally {
        removeLayoutHost($base, ['src']);
    }
});

test('Path::src() resolves app/ on an app/-only host', function () {
    $base = makeLayoutHost(['app']);

    try {
        Path::setBasePath($base);

        expect(Path::src('Http/Controllers/HomeController.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http/Controllers/HomeController.php');
    } finally {
        removeLayoutHost($base, ['app']);
    }
});

test('Path::src() defaults to app/ when neither layout dir exists yet (fresh host)', function () {
    $base = makeLayoutHost([]);

    try {
        Path::setBasePath($base);

        expect(Path::src('Booting.php'))
            ->toBe($base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Booting.php');
    } finally {
        removeLayoutHost($base, []);
    }
});
