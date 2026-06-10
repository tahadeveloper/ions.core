<?php

declare(strict_types=1);

use Ions\Bundles\Path;
use Ions\Foundation\Discovery;
use Ions\Foundation\Kernel;

/**
 * Unit tests for Ions\Foundation\Discovery: the host {src|app}/Providers scan,
 * the composer extra.ions.providers package scan (via the injectable metadata
 * seam) and the merged providers() list.
 */

function fixtureAppPath(): string
{
    return dirname(__DIR__, 2) . '/fixtures/app';
}

function fakePackageMetadata(): array
{
    $json = file_get_contents(dirname(__DIR__, 2) . '/fixtures/fake-vendor-package/composer.json');

    return [json_decode((string) $json, true)];
}

beforeEach(function () {
    Discovery::reset();
    Path::resetBasePath();
});

afterEach(function () {
    Discovery::reset();
    Path::resetBasePath();
});

test('hostProviders finds concrete providers in the fixture src/Providers dir', function () {
    Path::setBasePath(fixtureAppPath());

    expect(Discovery::hostProviders())->toContain(\IonsFixture\Providers\FixtureAutoProvider::class);
});

test('hostProviders skips abstract providers and non-provider classes', function () {
    Path::setBasePath(fixtureAppPath());

    $found = Discovery::hostProviders();

    expect($found)->not->toContain(\IonsFixture\Providers\AbstractFixtureProvider::class)
        ->and($found)->not->toContain(\IonsFixture\Providers\NotAProvider::class);
});

test('hostProviders returns [] when the host has no Providers directory', function () {
    Path::setBasePath(dirname(__DIR__, 2) . '/fixtures/app2');

    expect(Discovery::hostProviders())->toBe([]);
});

test('hostProviders works with the app/ fallback layout (no composer autoload)', function () {
    $base = sys_get_temp_dir() . '/ions-discovery-applayout-' . getmypid();
    @mkdir($base . '/app/Providers', 0777, true);
    file_put_contents($base . '/app/Providers/TempAppLayoutProvider.php', <<<'PHP'
<?php

namespace IonsTempFixture\Providers;

use Ions\Container\ServiceProvider;

class TempAppLayoutProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('temp.applayout.marker', true);
    }
}
PHP);

    try {
        Path::setBasePath($base);

        expect(Discovery::hostProviders())->toContain('IonsTempFixture\\Providers\\TempAppLayoutProvider');
    } finally {
        @unlink($base . '/app/Providers/TempAppLayoutProvider.php');
        @rmdir($base . '/app/Providers');
        @rmdir($base . '/app');
        @rmdir($base);
    }
});

test('packageProviders reads extra.ions.providers from injected metadata', function () {
    Discovery::useMetadata(fakePackageMetadata());

    expect(Discovery::packageProviders())->toBe([\Acme\FakePackage\FakePackageProvider::class]);
});

test('packageProviders skips unknown classes, non-providers and packages without extra', function () {
    Discovery::useMetadata([
        ['name' => 'acme/no-extra'],
        ['name' => 'acme/extra-not-ions', 'extra' => ['branch-alias' => ['dev-main' => '1.x-dev']]],
        ['name' => 'acme/garbage', 'extra' => ['ions' => ['providers' => [
            'Acme\\Missing\\NowhereProvider',          // class does not exist
            \IonsFixture\Providers\NotAProvider::class, // not a ServiceProvider
            \IonsFixture\Providers\AbstractFixtureProvider::class, // abstract
            42,                                         // not even a string
        ]]]],
        ['name' => 'acme/ions-fake-package', 'extra' => ['ions' => ['providers' => [
            \Acme\FakePackage\FakePackageProvider::class,
        ]]]],
    ]);

    expect(Discovery::packageProviders())->toBe([\Acme\FakePackage\FakePackageProvider::class]);
});

test('packageProviders memoizes the scan, useMetadata invalidates it, reset() restores real metadata', function () {
    Discovery::useMetadata(fakePackageMetadata());
    expect(Discovery::packageProviders())->toBe([\Acme\FakePackage\FakePackageProvider::class]);

    // Memoized after the first scan (no re-read on subsequent calls).
    $memo = new ReflectionProperty(Discovery::class, 'packageProviders');
    expect($memo->getValue())->toBe([\Acme\FakePackage\FakePackageProvider::class])
        ->and(Discovery::packageProviders())->toBe([\Acme\FakePackage\FakePackageProvider::class]);

    // Injecting new metadata invalidates the memo so the seam takes effect.
    Discovery::useMetadata([]);
    expect(Discovery::packageProviders())->toBe([]);

    // reset() drops both the memo and the injection → real installed.json
    // (this repo's dependencies declare no extra.ions.providers).
    Discovery::reset();
    expect(Discovery::packageProviders())->toBe([]);
});

test('packageProviders is robust without composer metadata (returns [])', function () {
    // No injected metadata + whatever installed.json this repo has: the repo's
    // real dependencies declare no extra.ions.providers, so the scan yields [].
    expect(Discovery::packageProviders())->toBe([]);
});

test('providers() merges framework defaults, then packages, then host, de-duplicated', function () {
    Path::setBasePath(fixtureAppPath());
    Discovery::useMetadata([
        // Also re-declares a framework default to prove de-duplication.
        ['name' => 'acme/ions-fake-package', 'extra' => ['ions' => ['providers' => [
            \Acme\FakePackage\FakePackageProvider::class,
            \Ions\Providers\ConfigProvider::class,
        ]]]],
    ]);

    $providers = Discovery::providers();
    $defaults = Kernel::defaultProviders();

    // Framework defaults first, in order.
    expect(array_slice($providers, 0, count($defaults)))->toBe($defaults);

    // Then package providers, then host providers.
    $packagePos = array_search(\Acme\FakePackage\FakePackageProvider::class, $providers, true);
    $hostPos = array_search(\IonsFixture\Providers\FixtureAutoProvider::class, $providers, true);
    expect($packagePos)->toBeInt()
        ->and($hostPos)->toBeInt()
        ->and($packagePos)->toBeGreaterThanOrEqual(count($defaults))
        ->and($hostPos)->toBeGreaterThan($packagePos);

    // De-duplicated: the re-declared ConfigProvider appears exactly once.
    expect(array_count_values($providers)[\Ions\Providers\ConfigProvider::class])->toBe(1)
        ->and($providers)->toBe(array_values(array_unique($providers)));
});
