<?php

/**
 * Phase 8.6 — discover:cache / discover:clear (the providers.php cache that
 * lets production boot skip the discovery scans entirely).
 */

use Ions\commands\DiscoverCacheCommand;
use Ions\commands\DiscoverClearCommand;
use Ions\Foundation\Kernel;

function discoverCacheFixture(): string
{
    return dirname(__DIR__, 2) . '/fixtures/app-cache';
}

function clearDiscoverArtifacts(): void
{
    foreach ([
        discoverCacheFixture() . '/var/cache/providers.php',
        dirname(__DIR__, 2) . '/fixtures/app/var/cache/providers.php',
    ] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

beforeEach(fn () => clearDiscoverArtifacts());
afterEach(fn () => clearDiscoverArtifacts());

test('discover:cache writes a valid loadable provider cache and lists the discovered providers', function () {
    $fx = discoverCacheFixture();
    bootFixtureKernel($fx);

    $tester = runConsoleCommand(new DiscoverCacheCommand());
    $file = $fx . '/var/cache/providers.php';

    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($file))->toBeTrue();

    // The file is a plain `<?php return [...];` and loads back to the exact
    // discovered list (this fixture has no host providers → pure defaults).
    $cached = require $file;
    expect($cached)->toBe(Kernel::defaultProviders())
        ->and($tester->getDisplay())->toContain(\Ions\Providers\ConfigProvider::class)
        ->and($tester->getDisplay())->toContain((string) count(Kernel::defaultProviders()));

    // Syntactically valid PHP.
    $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
    expect((string) $lint)->toContain('No syntax errors');
});

test('discover:cache includes host providers discovered from src/Providers', function () {
    $fx = dirname(__DIR__, 2) . '/fixtures/app';
    bootFixtureKernel($fx);

    $tester = runConsoleCommand(new DiscoverCacheCommand());
    $file = $fx . '/var/cache/providers.php';

    expect($tester->getStatusCode())->toBe(0);

    $cached = require $file;
    expect($cached)->toContain(\IonsFixture\Providers\FixtureAutoProvider::class)
        ->and($tester->getDisplay())->toContain(\IonsFixture\Providers\FixtureAutoProvider::class);
});

test('discover:clear removes the provider cache', function () {
    $fx = discoverCacheFixture();
    bootFixtureKernel($fx);

    runConsoleCommand(new DiscoverCacheCommand());
    expect(is_file($fx . '/var/cache/providers.php'))->toBeTrue();

    $tester = runConsoleCommand(new DiscoverClearCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/providers.php'))->toBeFalse();

    // Idempotent: clearing again succeeds and says it's already clear.
    $again = runConsoleCommand(new DiscoverClearCommand());
    expect($again->getStatusCode())->toBe(0)
        ->and($again->getDisplay())->toContain('already clear');
});
