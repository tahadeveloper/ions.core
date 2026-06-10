<?php

declare(strict_types=1);

use Ions\Foundation\Discovery;
use Ions\Foundation\Kernel;

/**
 * End-to-end discovery tests: providers found by the host scan and the
 * composer extra.ions.providers scan must actually be registered AND booted
 * by Kernel::boot(), and both escape hatches (explicit app.providers /
 * app.discovery=false) must skip the scans entirely.
 */

/**
 * Scaffold a minimal bootable host app in a temp dir (modelled on
 * tests/fixtures/app2) with a custom config/app.php and a sentinel provider
 * in src/Providers. Returns the app base path.
 */
function discoveryScaffoldHostApp(string $name, string $appConfig, string $sentinelClass): string
{
    $base = sys_get_temp_dir() . '/ions-discovery-' . $name . '-' . getmypid();
    discoveryRemoveDir($base);

    mkdir($base . '/config', 0777, true);
    mkdir($base . '/src/Providers', 0777, true);
    mkdir($base . '/var', 0777, true);
    mkdir($base . '/public', 0777, true);
    mkdir($base . '/routes', 0777, true);

    file_put_contents($base . '/.env', '');
    file_put_contents($base . '/config/app.php', $appConfig);
    file_put_contents($base . '/config/database.php', <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
PHP);

    $short = substr($sentinelClass, (int) strrpos($sentinelClass, '\\') + 1);
    $namespace = substr($sentinelClass, 0, (int) strrpos($sentinelClass, '\\'));
    file_put_contents($base . '/src/Providers/' . $short . '.php', <<<PHP
<?php

namespace {$namespace};

use Ions\Container\ServiceProvider;

class {$short} extends ServiceProvider
{
    public function register(): void
    {
        \$this->container->instance('discovery.sentinel.marker', '{$short}');
    }
}
PHP);

    return $base;
}

function discoveryRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

test('booting the fixture app auto-registers and boots the host provider from src/Providers', function () {
    bootFixtureKernel();

    $app = Kernel::app();
    expect($app->has('fixture.auto.marker'))->toBeTrue()
        ->and($app->get('fixture.auto.marker'))->toBe('auto-discovered')
        ->and($app->get('fixture.auto.booted'))->toBeTrue();
});

test('host scan never registers abstract or non-provider classes from the Providers dir', function () {
    bootFixtureKernel();

    expect(Kernel::app()->has('fixture.abstract.marker'))->toBeFalse();
});

test('a fake vendor package declaring extra.ions.providers is registered and booted with zero host config', function () {
    Kernel::resetForTesting();

    $package = json_decode(
        (string) file_get_contents(__DIR__ . '/../fixtures/fake-vendor-package/composer.json'),
        true
    );
    Discovery::useMetadata([$package]);

    Kernel::boot(__DIR__ . '/../fixtures/app');

    $app = Kernel::app();
    expect($app->has('fake.package.marker'))->toBeTrue()
        ->and($app->get('fake.package.marker'))->toBe('package-discovered')
        ->and($app->get('fake.package.booted'))->toBeTrue();
});

test('explicit app.providers takes full control: discovery is skipped entirely', function () {
    $sentinel = 'IonsDiscoverySentinel\\ExplicitSentinelProvider';
    $base = discoveryScaffoldHostApp('explicit', <<<'PHP'
<?php

return [
    'name' => 'DiscoveryExplicit',
    'app_url' => 'http://localhost',
    'templates' => [],
    'localization' => ['locale' => 'en'],
    'providers' => [
        \Ions\Providers\ConfigProvider::class,
        \Ions\Providers\FilesystemProvider::class,
    ],
];
PHP, $sentinel);

    try {
        bootFixtureKernel($base);

        $app = Kernel::app();
        // The sentinel provider sits in src/Providers but must NOT be bound —
        // and its class must never even have been loaded (the scan never ran).
        expect($app->has('discovery.sentinel.marker'))->toBeFalse()
            ->and(class_exists($sentinel, false))->toBeFalse()
            // Explicit list omits DatabaseProvider: 'db' must not exist.
            ->and($app->has('db'))->toBeFalse()
            ->and($app->has('filesystem'))->toBeTrue();
    } finally {
        discoveryRemoveDir($base);
    }
});

test('app.discovery=false disables the scans but keeps the framework defaults', function () {
    $sentinel = 'IonsDiscoverySentinel\\DisabledSentinelProvider';
    $base = discoveryScaffoldHostApp('disabled', <<<'PHP'
<?php

return [
    'name' => 'DiscoveryDisabled',
    'app_url' => 'http://localhost',
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],
    'discovery' => false,
];
PHP, $sentinel);

    try {
        bootFixtureKernel($base);

        $app = Kernel::app();
        expect($app->has('discovery.sentinel.marker'))->toBeFalse()
            ->and(class_exists($sentinel, false))->toBeFalse()
            // Framework defaults still boot: DatabaseProvider binds 'db'.
            ->and($app->has('db'))->toBeTrue();
    } finally {
        discoveryRemoveDir($base);
    }
});
