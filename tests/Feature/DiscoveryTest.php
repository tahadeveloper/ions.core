<?php

declare(strict_types=1);

use Ions\Container\ServiceProvider;
use Ions\Foundation\Discovery;
use Ions\Foundation\Kernel;

/**
 * Autoloadable sentinel for the providers-cache tests: it lives only in this
 * test file (never in any scanned Providers dir or composer metadata), so it
 * can ONLY be registered by loading var/cache/providers.php.
 */
class DiscoveryCachedSentinelProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('discovery.cached.marker', 'from-cache');
    }
}

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

/**
 * Minimal config/app.php for a discovery-enabled host (no 'providers' key,
 * discovery on). $extra is spliced verbatim into the returned array.
 */
function discoveryPlainAppConfig(string $name, string $extra = ''): string
{
    return <<<PHP
<?php

return [
    'name' => '{$name}',
    'app_url' => 'http://localhost',
    'templates' => [],
    'localization' => ['locale' => 'en'],
    {$extra}
];
PHP;
}

/**
 * Write a discover:cache-style providers cache into the scaffolded host.
 *
 * @param list<class-string> $providers
 */
function discoveryWriteProvidersCache(string $base, array $providers): string
{
    @mkdir($base . '/var/cache', 0777, true);
    $file = $base . '/var/cache/providers.php';
    file_put_contents($file, "<?php\n\nreturn " . var_export($providers, true) . ";\n");

    return $file;
}

test('var/cache/providers.php is used when present: cached provider registers, all scans are skipped', function () {
    $sentinel = 'IonsDiscoverySentinel\\CacheHitSentinelProvider';
    $base = discoveryScaffoldHostApp('cachehit', discoveryPlainAppConfig('DiscoveryCacheHit'), $sentinel);
    discoveryWriteProvidersCache($base, array_merge(
        Kernel::defaultProviders(),
        [DiscoveryCachedSentinelProvider::class],
    ));

    try {
        Kernel::resetForTesting();
        // A package sentinel: if the composer scan ran, this would register.
        $package = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/fake-vendor-package/composer.json'),
            true
        );
        Discovery::useMetadata([$package]);

        Kernel::boot($base);

        $app = Kernel::app();
        expect($app->get('discovery.cached.marker'))->toBe('from-cache')
            // Host scan skipped: the src/Providers sentinel was never registered
            // nor even loaded.
            ->and($app->has('discovery.sentinel.marker'))->toBeFalse()
            ->and(class_exists($sentinel, false))->toBeFalse()
            // Package scan skipped: the fake package's provider is absent.
            ->and($app->has('fake.package.marker'))->toBeFalse();
    } finally {
        discoveryRemoveDir($base);
    }
});

test('APP_DEBUG=true bypasses the providers cache (live discovery wins)', function () {
    $sentinel = 'IonsDiscoverySentinel\\CacheDebugSentinelProvider';
    $base = discoveryScaffoldHostApp('cachedebug', discoveryPlainAppConfig('DiscoveryCacheDebug'), $sentinel);
    discoveryWriteProvidersCache($base, array_merge(
        Kernel::defaultProviders(),
        [DiscoveryCachedSentinelProvider::class],
    ));

    $originalDebug = getenv('APP_DEBUG');
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        bootFixtureKernel($base);

        $app = Kernel::app();
        // Live host scan ran (cache ignored): the src/Providers sentinel is in.
        expect($app->get('discovery.sentinel.marker'))->toBe('CacheDebugSentinelProvider')
            // The cache-only provider must NOT have been registered.
            ->and($app->has('discovery.cached.marker'))->toBeFalse();
    } finally {
        if ($originalDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
        discoveryRemoveDir($base);
    }
});

test('a stale cached provider FQCN is filtered with a logged warning, never a fatal', function () {
    $sentinel = 'IonsDiscoverySentinel\\CacheStaleSentinelProvider';
    $base = discoveryScaffoldHostApp('cachestale', discoveryPlainAppConfig('DiscoveryCacheStale'), $sentinel);
    discoveryWriteProvidersCache($base, array_merge(
        Kernel::defaultProviders(),
        ['Acme\\Vanished\\GoneProvider'], // class no longer exists
    ));

    try {
        bootFixtureKernel($base);

        // Boot survived and the rest of the cached list registered normally.
        expect(Kernel::app()->has('filesystem'))->toBeTrue();

        $log = (string) @file_get_contents($base . '/var/logs/app.log');
        expect($log)->toContain('Acme\\Vanished\\GoneProvider');
    } finally {
        discoveryRemoveDir($base);
    }
});

test('a providers cache that filters down to zero entries is rejected — boot falls back to live discovery', function () {
    $sentinel = 'IonsDiscoverySentinel\\CacheEmptySentinelProvider';
    $base = discoveryScaffoldHostApp('cacheempty', discoveryPlainAppConfig('DiscoveryCacheEmpty'), $sentinel);
    // Every cached FQCN is stale: filtering leaves [] — which must mean
    // "fall back to live discovery", never "register zero providers".
    discoveryWriteProvidersCache($base, ['Acme\\Vanished\\GoneProvider', 'Acme\\Vanished\\AlsoGoneProvider']);

    try {
        bootFixtureKernel($base);

        // The degenerate cache yields null (not []) ...
        expect(Discovery::cachedProviders())->toBeNull();

        $app = Kernel::app();
        // ... so live discovery ran: framework defaults + the host sentinel.
        expect($app->has('filesystem'))->toBeTrue()
            ->and($app->get('discovery.sentinel.marker'))->toBe('CacheEmptySentinelProvider');
    } finally {
        discoveryRemoveDir($base);
    }
});

test('a host provider file with top-level output never leaks output into the boot', function () {
    $sentinel = 'IonsDiscoverySentinel\\EchoHostSentinelProvider';
    $base = discoveryScaffoldHostApp('echoleak', discoveryPlainAppConfig('DiscoveryEchoLeak'), $sentinel);
    file_put_contents($base . '/src/Providers/EchoLeakProvider.php', <<<'PHP'
<?php

namespace IonsDiscoverySentinel;

use Ions\Container\ServiceProvider;

echo 'SIDE-EFFECT-OUTPUT';

class EchoLeakProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance('discovery.echo.marker', true);
    }
}
PHP);

    try {
        ob_start();
        bootFixtureKernel($base);
        $output = ob_get_clean();

        expect($output)->toBe('')
            // The provider itself still registers — only its output is eaten.
            ->and(Kernel::app()->has('discovery.echo.marker'))->toBeTrue();
    } finally {
        discoveryRemoveDir($base);
    }
});

test('a broken-syntax host provider file logs a warning naming the file and boot continues', function () {
    $sentinel = 'IonsDiscoverySentinel\\BrokenHostSentinelProvider';
    $base = discoveryScaffoldHostApp('brokensyntax', discoveryPlainAppConfig('DiscoveryBroken'), $sentinel);
    file_put_contents($base . '/src/Providers/BrokenSyntaxProvider.php', <<<'PHP'
<?php

namespace IonsDiscoverySentinel;

use Ions\Container\ServiceProvider;

class BrokenSyntaxProvider extends ServiceProvider
{
    public function register(): void
    {
        this is not valid php at all
    }
}
PHP);

    try {
        bootFixtureKernel($base);

        // Boot continued: the healthy sibling provider still registered.
        expect(Kernel::app()->get('discovery.sentinel.marker'))->toBe('BrokenHostSentinelProvider');

        $log = (string) @file_get_contents($base . '/var/logs/app.log');
        expect($log)->toContain('BrokenSyntaxProvider.php');
    } finally {
        discoveryRemoveDir($base);
    }
});

test('app.dont_discover skips a composer package by exact name', function () {
    $sentinel = 'IonsDiscoverySentinel\\DontDiscoverSentinelProvider';
    $base = discoveryScaffoldHostApp('dontdiscover', discoveryPlainAppConfig(
        'DiscoveryDontDiscover',
        "'dont_discover' => ['acme/ions-fake-package'],"
    ), $sentinel);

    try {
        Kernel::resetForTesting();
        $package = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/fake-vendor-package/composer.json'),
            true
        );
        Discovery::useMetadata([$package]);

        Kernel::boot($base);

        $app = Kernel::app();
        expect($app->has('fake.package.marker'))->toBeFalse()
            // Host scan is unaffected by dont_discover.
            ->and($app->has('discovery.sentinel.marker'))->toBeTrue();
    } finally {
        discoveryRemoveDir($base);
    }
});

test('app.dont_discover matches the exact package name only (no prefix matching)', function () {
    $sentinel = 'IonsDiscoverySentinel\\DontDiscoverExactSentinelProvider';
    $base = discoveryScaffoldHostApp('dontdiscoverexact', discoveryPlainAppConfig(
        'DiscoveryDontDiscoverExact',
        "'dont_discover' => ['acme/ions'],"
    ), $sentinel);

    try {
        Kernel::resetForTesting();
        $package = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/fake-vendor-package/composer.json'),
            true
        );
        Discovery::useMetadata([$package]);

        Kernel::boot($base);

        // 'acme/ions' must not filter 'acme/ions-fake-package'.
        expect(Kernel::app()->has('fake.package.marker'))->toBeTrue();
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
