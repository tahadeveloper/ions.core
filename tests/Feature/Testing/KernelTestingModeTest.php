<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/*
|--------------------------------------------------------------------------
| Kernel testing mode (resetForTesting side effects)
|--------------------------------------------------------------------------
| 1. Once resetForTesting() has run, a boot failure must THROW (so the test
|    runner reports it) instead of die()-ing the whole process — even when
|    APP_DEBUG is off, which is the production die() path.
| 2. resetForTesting() must clear the Request CLASS static set by
|    Request::setTrustedHosts() during a previous boot, or every later
|    fixture boot inherits the old app's host allowlist.
*/

test('boot failure throws in testing mode even when APP_DEBUG is off (no die)', function () {
    // Force APP_DEBUG off. Dotenv::createImmutable() never overrides an
    // already-set variable, so the fixture's own APP_DEBUG=true cannot land
    // and failBoot() takes its non-debug branch — which would die() without
    // the testing-mode flag, killing this very test run.
    $originalGetenv = getenv('APP_DEBUG');
    $originalEnv = $_ENV['APP_DEBUG'] ?? null;
    $originalServer = $_SERVER['APP_DEBUG'] ?? null;

    putenv('APP_DEBUG=false');
    $_ENV['APP_DEBUG'] = 'false';
    $_SERVER['APP_DEBUG'] = 'false';

    try {
        // bootFixtureKernel() calls Kernel::resetForTesting() first — that is
        // what arms testing mode. config/broken.php throws 'boom' mid-boot.
        expect(fn () => bootFixtureKernel(__DIR__ . '/../../fixtures/app-badconfig'))
            ->toThrow(RuntimeException::class, 'boom');
    } finally {
        if ($originalGetenv === false) {
            putenv('APP_DEBUG');
        } else {
            putenv('APP_DEBUG=' . $originalGetenv);
        }
        if ($originalEnv === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $originalEnv;
        }
        if ($originalServer === null) {
            unset($_SERVER['APP_DEBUG']);
        } else {
            $_SERVER['APP_DEBUG'] = $originalServer;
        }
    }
});

test('resetForTesting clears trusted-host patterns left behind by a previous boot', function () {
    // Minimal fixture whose boot sets Request::setTrustedHosts() via
    // config('app.trusted_hosts') — the static this test proves gets cleared.
    $dir = sys_get_temp_dir() . '/ions-trusted-hosts-' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0777, true);
    file_put_contents($dir . '/.env', "APP_NAME=IonsTrustedFixture\n");
    copy(__DIR__ . '/../../fixtures/app2/config/database.php', $dir . '/config/database.php');
    file_put_contents($dir . '/config/app.php', <<<'PHP'
<?php

return [
    'name' => 'IonsTrustedFixture',
    'app_url' => 'http://trusted.example',
    'trusted_hosts' => ['^trusted\.example$'],
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],
];
PHP);

    try {
        bootFixtureKernel($dir);

        // Sanity: the boot above poisoned the Request CLASS static — any
        // other host is now rejected process-wide.
        $poisoned = Request::create('http://localhost/ping');
        expect(fn () => $poisoned->getHost())->toThrow(SuspiciousOperationException::class);

        // Re-booting the normal fixture (resetForTesting inside) must clear it.
        bootFixtureKernel(__DIR__ . '/../../fixtures/app');

        $request = Request::create('http://localhost/ping');
        expect($request->getHost())->toBe('localhost');

        $response = Kernel::handle($request);
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getContent())->toBe('pong');
    } finally {
        Request::setTrustedHosts([]); // belt & braces for sibling tests
        @unlink($dir . '/.env');
        @unlink($dir . '/config/app.php');
        @unlink($dir . '/config/database.php');
        @rmdir($dir . '/config');
        @rmdir($dir);
    }
});
