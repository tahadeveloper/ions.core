<?php

declare(strict_types=1);

use Ions\Bundles\Path;

/**
 * Laravel-compatible path globals defined in src/helpers.php. These delegate to
 * Ions\Bundles\Path and must mirror Laravel's semantics: empty arg returns the
 * directory root WITHOUT a trailing slash; a sub-path is joined with a single
 * separator (no doubled slashes).
 */
beforeEach(function () {
    bootFixtureKernel();
});

/**
 * Strip the trailing separator a raw Path::xxx('') call leaves behind so we can
 * compare against the normalized helper output.
 */
function expectedRoot(string $raw): string
{
    return rtrim($raw, DIRECTORY_SEPARATOR);
}

test('all path helpers are defined after helpers load', function () {
    foreach ([
        'base_path',
        'app_path',
        'config_path',
        'database_path',
        'public_path',
        'storage_path',
        'resource_path',
    ] as $fn) {
        expect(function_exists($fn))->toBeTrue("helper {$fn} should be defined");
    }
});

test('base_path() returns the host root without a trailing slash', function () {
    $root = expectedRoot(Path::root(''));

    expect(base_path())->toBe($root)
        ->and(base_path())->not->toEndWith(DIRECTORY_SEPARATOR);
});

test('base_path(sub) joins with a single separator', function () {
    $root = expectedRoot(Path::root(''));

    expect(base_path('config/app.php'))->toBe($root . DIRECTORY_SEPARATOR . 'config/app.php')
        ->and(base_path('config/app.php'))->not->toContain(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
});

test('app_path maps to Path::src', function () {
    expect(app_path())->toBe(expectedRoot(Path::src('')))
        ->and(app_path('Models/User.php'))->toBe(rtrim(Path::src(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Models/User.php');
});

test('config_path maps to Path::config', function () {
    expect(config_path())->toBe(expectedRoot(Path::config('')))
        ->and(config_path('app.php'))->toBe(rtrim(Path::config(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app.php');
});

test('database_path maps to Path::database', function () {
    expect(database_path())->toBe(expectedRoot(Path::database('')))
        ->and(database_path('database.sqlite'))->toBe(rtrim(Path::database(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'database.sqlite');
});

test('public_path maps to Path::public', function () {
    expect(public_path())->toBe(expectedRoot(Path::public('')))
        ->and(public_path('uploads/x.png'))->toBe(rtrim(Path::public(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads/x.png');
});

test('storage_path maps to Path::var (Ions storage is var/)', function () {
    expect(storage_path())->toBe(expectedRoot(Path::var('')))
        ->and(storage_path('logs/app.log'))->toBe(rtrim(Path::var(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs/app.log');
});

test('resource_path maps to <root>/resources (Laravel convention)', function () {
    $root = expectedRoot(Path::root(''));

    expect(resource_path())->toBe($root . DIRECTORY_SEPARATOR . 'resources')
        ->and(resource_path('js/app.js'))->toBe($root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'js/app.js');
});

test('no path helper produces a doubled separator for empty or sub paths', function () {
    $dbl = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;

    foreach ([
        base_path(), base_path('a/b'),
        app_path(), app_path('a/b'),
        config_path(), config_path('a/b'),
        database_path(), database_path('a/b'),
        public_path(), public_path('a/b'),
        storage_path(), storage_path('a/b'),
        resource_path(), resource_path('a/b'),
    ] as $value) {
        expect($value)->not->toContain($dbl);
    }
});

test('base_path no longer fatals when used like Illuminate connectors do', function () {
    // Illuminate\Database\Connectors\SQLiteConnector::parseDatabasePath() calls
    // the global base_path(). Before this fix that call fatally errored with
    // "Call to undefined function". Mirror the shape of that usage.
    $resolved = base_path('database.sqlite');

    expect($resolved)->toBeString()
        ->and($resolved)->toEndWith('database.sqlite');

    // realpath() over base_path() is the exact pattern connectors use; it must
    // not raise even when the file is absent (returns false in that case).
    expect(fn () => realpath(base_path('does-not-exist')))->not->toThrow(Throwable::class);
});
