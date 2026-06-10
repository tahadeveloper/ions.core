<?php

use Ions\Bundles\Path;

/**
 * Path::tests() — host tests/ directory resolution (Phase 8.4d, make:test).
 * tests/ lives at the host ROOT (not under src/ or app/), so the helper must
 * be layout-independent and honour an injected base path.
 */

afterEach(function () {
    Path::resetBasePath();
});

test('Path::tests() resolves under the injected base path', function () {
    Path::setBasePath('/tmp/host-app');

    expect(Path::tests('PingTest.php'))
        ->toBe('/tmp/host-app' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'PingTest.php');
});

test('Path::tests() with no argument returns the tests directory itself', function () {
    Path::setBasePath('/tmp/host-app');

    expect(Path::tests())
        ->toBe('/tmp/host-app' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR);
});

test('Path::tests() is independent of the src/app layout', function () {
    // The fixture app uses a src/ layout; tests/ must still resolve at the root.
    $fixture = realpath(__DIR__ . '/../../fixtures/app');
    Path::setBasePath($fixture);

    expect(Path::tests('FooTest.php'))
        ->toBe($fixture . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'FooTest.php')
        ->and(Path::tests('FooTest.php'))->not->toContain(DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR);
});
