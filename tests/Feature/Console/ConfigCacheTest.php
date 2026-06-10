<?php

/**
 * Phase 8.1 — config cache (config:cache / config:clear).
 */

use Ions\commands\ConfigCacheCommand;
use Ions\commands\ConfigClearCommand;

function configCacheFixture(): string
{
    return dirname(__DIR__, 2) . '/fixtures/app-cache';
}

function clearConfigCacheFile(): void
{
    $file = configCacheFixture() . '/var/cache/config.php';
    if (is_file($file)) {
        unlink($file);
    }
}

beforeEach(fn () => clearConfigCacheFile());
afterEach(fn () => clearConfigCacheFile());

test('config:cache merges all config files and a cached boot sees identical values', function () {
    $fx = configCacheFixture();

    bootFixtureKernel($fx);
    $live = [
        config('app.name'),
        config('app.auth.public_paths'),
        config('database.default'),
        config('session.driver'),
    ];

    $tester = runConsoleCommand(new ConfigCacheCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/config.php'))->toBeTrue();

    bootFixtureKernel($fx);
    expect([
        config('app.name'),
        config('app.auth.public_paths'),
        config('database.default'),
        config('session.driver'),
    ])->toBe($live);
});

test('boot actually loads the cached config when present (marker key visible)', function () {
    $fx = configCacheFixture();

    bootFixtureKernel($fx);
    runConsoleCommand(new ConfigCacheCommand());

    // Inject a marker only the cached file contains.
    $file = $fx . '/var/cache/config.php';
    $items = require $file;
    $items['cache_marker'] = ['present' => true];
    file_put_contents($file, "<?php\n\nreturn " . var_export($items, true) . ";\n");

    bootFixtureKernel($fx);
    expect(config('cache_marker.present'))->toBeTrue();
});

test('APP_DEBUG=true bypasses the config cache', function () {
    $fx = configCacheFixture();

    bootFixtureKernel($fx);
    runConsoleCommand(new ConfigCacheCommand());

    $file = $fx . '/var/cache/config.php';
    $items = require $file;
    $items['cache_marker'] = ['present' => true];
    file_put_contents($file, "<?php\n\nreturn " . var_export($items, true) . ";\n");

    $originalDebug = getenv('APP_DEBUG');
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        bootFixtureKernel($fx);
        expect(config('cache_marker.present'))->toBeNull()
            ->and(config('app.name'))->toBe('IonsFixtureCache');
    } finally {
        if ($originalDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
    }
});

test('config:cache fails loudly when a config value contains a Closure, naming the key', function () {
    $fx = configCacheFixture();
    $closureConfig = $fx . '/config/tmpclosure.php';
    file_put_contents($closureConfig, "<?php\n\nreturn ['nested' => ['bad' => fn () => 1]];\n");

    try {
        bootFixtureKernel($fx);
        $tester = runConsoleCommand(new ConfigCacheCommand());
        expect($tester->getStatusCode())->not->toBe(0)
            ->and($tester->getDisplay())->toContain('tmpclosure.nested.bad')
            ->and($tester->getDisplay())->toContain('Closure')
            ->and(is_file($fx . '/var/cache/config.php'))->toBeFalse();
    } finally {
        if (is_file($closureConfig)) {
            unlink($closureConfig);
        }
    }
});

test('config:clear removes the cached config file', function () {
    $fx = configCacheFixture();

    bootFixtureKernel($fx);
    runConsoleCommand(new ConfigCacheCommand());
    expect(is_file($fx . '/var/cache/config.php'))->toBeTrue();

    $tester = runConsoleCommand(new ConfigClearCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/config.php'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Finding 4 — config:cache guards
// ---------------------------------------------------------------------------

test('config:cache fails with a clear error when a config value is an object without __set_state', function () {
    $fx = configCacheFixture();
    $objectConfig = $fx . '/config/tmpobject.php';

    // An object that does NOT implement __set_state would fatal at require-time
    // with no context; the command must refuse to write the cache and name the key.
    file_put_contents($objectConfig, "<?php\n\nreturn ['svc' => ['handler' => new stdClass()]];\n");

    try {
        bootFixtureKernel($fx);
        $tester = runConsoleCommand(new ConfigCacheCommand());
        expect($tester->getStatusCode())->not->toBe(0)
            ->and($tester->getDisplay())->toContain('tmpobject.svc.handler')
            ->and($tester->getDisplay())->toContain('__set_state')
            ->and(is_file($fx . '/var/cache/config.php'))->toBeFalse();
    } finally {
        if (is_file($objectConfig)) {
            unlink($objectConfig);
        }
    }
});

test('config:cache warns when run with APP_DEBUG=true', function () {
    $fx = configCacheFixture();

    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        bootFixtureKernel($fx);
        $tester = runConsoleCommand(new ConfigCacheCommand());
        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('APP_DEBUG=true')
            ->and($tester->getDisplay())->toContain('production');
    } finally {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    }
});
