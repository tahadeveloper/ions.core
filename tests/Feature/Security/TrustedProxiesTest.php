<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Session\SessionManager;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Trusted proxies (Phase 10.1)
|--------------------------------------------------------------------------
| config('app.trusted_proxies') — IPs/CIDRs, or '*' to trust the directly
| connecting peer — is applied via Request::setTrustedProxies() at boot AND
| re-applied per request in Kernel::handle() (so '*' resolves against the
| ACTUAL request peer: worker mode, tests). With a trusted peer the
| X-Forwarded-* headers drive isSecure()/getClientIp(), which unlocks HSTS
| and session cookie_secure => 'auto' behind TLS-terminating proxies.
| The header set is configurable via config('app.trusted_proxy_headers').
*/

/**
 * Build a throwaway host app whose config/app.php carries the given extra
 * config lines (same temp-fixture pattern as KernelTestingModeTest) and
 * return [dir, cleanup-closure].
 *
 * @return array{0: string, 1: Closure(): void}
 */
function makeProxyFixture(string $extraConfig): array
{
    $dir = sys_get_temp_dir() . '/ions-trusted-proxies-' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0777, true);
    file_put_contents($dir . '/.env', "APP_NAME=IonsProxyFixture\n");
    copy(__DIR__ . '/../../fixtures/app2/config/database.php', $dir . '/config/database.php');
    file_put_contents($dir . '/config/app.php', <<<PHP
<?php

return [
    'name' => 'IonsProxyFixture',
    'app_url' => 'http://localhost',
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],
    {$extraConfig}
];
PHP);

    $cleanup = static function () use ($dir): void {
        @unlink($dir . '/.env');
        @unlink($dir . '/config/app.php');
        @unlink($dir . '/config/database.php');
        @rmdir($dir . '/config');
        @rmdir($dir);
    };

    return [$dir, $cleanup];
}

afterEach(function () {
    // Belt & braces: never leak proxy trust into sibling test files. The
    // header-set arg is required by Symfony but inert with no proxies.
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

// ------------------------------------------------------------ boot-time path

test('boot applies app.trusted_proxies: X-Forwarded-* honored from a trusted peer', function () {
    [$dir, $cleanup] = makeProxyFixture("'trusted_proxies' => ['10.0.0.1'],");

    try {
        bootFixtureKernel($dir);

        expect(Request::getTrustedProxies())->toBe(['10.0.0.1']);

        $request = Request::create('http://localhost/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        expect($request->isSecure())->toBeTrue()
            ->and($request->getClientIp())->toBe('203.0.113.7');
    } finally {
        $cleanup();
    }
});

test('X-Forwarded-* headers from an untrusted peer are ignored', function () {
    [$dir, $cleanup] = makeProxyFixture("'trusted_proxies' => ['10.0.0.1'],");

    try {
        bootFixtureKernel($dir);

        $request = Request::create('http://localhost/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        expect($request->isSecure())->toBeFalse()
            ->and($request->getClientIp())->toBe('192.0.2.9');
    } finally {
        $cleanup();
    }
});

test('CIDR entries in app.trusted_proxies are honored', function () {
    [$dir, $cleanup] = makeProxyFixture("'trusted_proxies' => ['10.0.0.0/8'],");

    try {
        bootFixtureKernel($dir);

        $request = Request::create('http://localhost/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        expect($request->isSecure())->toBeTrue()
            ->and($request->getClientIp())->toBe('203.0.113.7');
    } finally {
        $cleanup();
    }
});

test("'*' at boot maps to Symfony's REMOTE_ADDR token (classic FPM path)", function () {
    [$dir, $cleanup] = makeProxyFixture("'trusted_proxies' => ['*'],");

    $hadRemoteAddr = array_key_exists('REMOTE_ADDR', $_SERVER);
    $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '10.9.9.9';

    try {
        bootFixtureKernel($dir);

        // Symfony substitutes the 'REMOTE_ADDR' token from $_SERVER at set time.
        expect(Request::getTrustedProxies())->toBe(['10.9.9.9']);
    } finally {
        if ($hadRemoteAddr) {
            $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        $cleanup();
    }
});

// --------------------------------------------------------- per-request path

test("'*' trusts the actual connecting peer of each handled request", function () {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['*']]);

    $request = Request::create('http://localhost/proxy-echo', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ]);

    $response = Kernel::handle($request);

    expect($response->getStatusCode())->toBe(200);

    $echo = json_decode((string) $response->getContent(), true);
    expect($echo['secure'])->toBeTrue()
        ->and($echo['ip'])->toBe('203.0.113.7');
});

test('HSTS is emitted on responses handled behind a trusted proxy', function () {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['198.51.100.10']]);

    $request = Request::create('http://localhost/proxy-echo', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response = Kernel::handle($request);

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

test('HSTS stays absent when no trusted proxies are configured', function () {
    bootFixtureKernel();

    $request = Request::create('http://localhost/proxy-echo', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response = Kernel::handle($request);

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test("session cookie_secure 'auto' resolves secure behind a trusted proxy", function () {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['198.51.100.10']]);

    Kernel::handle(Request::create('http://localhost/ping', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]));

    $options = (new SessionManager(['driver' => 'array', 'cookie_secure' => 'auto']))->nativeOptions();
    expect($options['cookie_secure'])->toBeTrue();
});

test("session cookie_secure 'auto' stays insecure when the peer is untrusted", function () {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['10.0.0.1']]);

    Kernel::handle(Request::create('http://localhost/ping', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]));

    $options = (new SessionManager(['driver' => 'array', 'cookie_secure' => 'auto']))->nativeOptions();
    expect($options['cookie_secure'])->toBeFalse();
});

// ------------------------------------------------------- header-set mapping

test('app.trusted_proxy_headers maps friendly names and int bitmasks', function (mixed $configured, int $expected) {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['10.0.0.1']]);
    config(['app.trusted_proxy_headers' => $configured]);

    Kernel::handle(Request::create('http://localhost/ping', 'GET', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
    ]));

    expect(Request::getTrustedHeaderSet())->toBe($expected);
})->with([
    'default xff combo' => [
        'xff',
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
    ],
    'aws-elb' => ['aws-elb', Request::HEADER_X_FORWARDED_AWS_ELB],
    'traefik' => ['traefik', Request::HEADER_X_FORWARDED_TRAEFIK],
    'forwarded (RFC 7239)' => ['forwarded', Request::HEADER_FORWARDED],
    'int bitmask passthrough' => [
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
    ],
]);

test("the 'forwarded' header set honors the RFC 7239 Forwarded header", function () {
    bootFixtureKernel();
    config(['app.trusted_proxies' => ['*'], 'app.trusted_proxy_headers' => 'forwarded']);

    $request = Request::create('http://localhost/proxy-echo', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_FORWARDED' => 'for=203.0.113.7;proto=https',
    ]);

    $response = Kernel::handle($request);

    $echo = json_decode((string) $response->getContent(), true);
    expect($echo['secure'])->toBeTrue()
        ->and($echo['ip'])->toBe('203.0.113.7');
});

// --------------------------------------------------------------- hygiene

test('resetForTesting clears trusted proxies left behind by a previous boot', function () {
    [$dir, $cleanup] = makeProxyFixture("'trusted_proxies' => ['10.0.0.1'],");

    try {
        bootFixtureKernel($dir);

        // Sanity: the boot above poisoned the Request CLASS static.
        expect(Request::getTrustedProxies())->toBe(['10.0.0.1']);

        // Re-booting the normal fixture (resetForTesting inside) must clear it.
        bootFixtureKernel(__DIR__ . '/../../fixtures/app');

        expect(Request::getTrustedProxies())->toBe([]);

        $request = Request::create('http://localhost/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        expect($request->isSecure())->toBeFalse();
    } finally {
        $cleanup();
    }
});
