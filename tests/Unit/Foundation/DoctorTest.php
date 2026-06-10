<?php

declare(strict_types=1);

use Ions\Foundation\Doctor;
use Ions\Foundation\Kernel;

/*
|--------------------------------------------------------------------------
| ions doctor — host-app diagnostics (Task 8.6c)
|--------------------------------------------------------------------------
| Doctor::run() executes every host-app health check and returns structured
| results ({id, label, status, message}). FAIL = critical misconfig (short
| APP_KEY, unwritable var/, DB configured-but-unreachable, missing PHP
| extension); WARN = risky-but-running (debug on, wildcard CORS, explicit
| insecure cookies, no trusted hosts); SKIP = not applicable; INFO = state
| worth knowing (cache present/absent, discovery mode).
*/

/**
 * Find a check result by id, failing loudly when absent.
 *
 * @param list<array{id: string, label: string, status: string, message: string}> $results
 * @return array{id: string, label: string, status: string, message: string}
 */
function doctorCheck(array $results, string $id): array
{
    foreach ($results as $result) {
        if ($result['id'] === $id) {
            return $result;
        }
    }

    throw new RuntimeException("Doctor check '{$id}' not found in results.");
}

/**
 * Mutate an environment variable across every channel env() may read
 * ($_SERVER, $_ENV, getenv) and return a restorer closure.
 */
function doctorSetEnv(string $key, ?string $value): Closure
{
    $original = [
        'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
        'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
        'getenv' => getenv($key),
    ];

    if ($value === null) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    } else {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    return static function () use ($key, $original): void {
        if ($original['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original['server'];
        }
        if ($original['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original['env'];
        }
        if ($original['getenv'] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $original['getenv']);
        }
    };
}

// ------------------------------------------------------------- healthy host

test('a healthy fixture host produces no failures', function () {
    bootFixtureKernel();

    $results = Doctor::run();

    $failures = array_filter($results, fn (array $r) => $r['status'] === Doctor::FAIL);

    expect($failures)->toBe([]);
});

test('every advertised check id is present in the results', function () {
    bootFixtureKernel();

    $ids = array_column(Doctor::run(), 'id');

    foreach ([
        'env_loaded',
        'app_key',
        'app_url',
        'writable_var',
        'writable_cache',
        'writable_logs',
        'writable_templates',
        'route_cache',
        'config_cache',
        'providers_cache',
        'db',
        'php_extensions',
        'csrf',
        'trusted_hosts',
        'session_cookies',
        'cors',
        'debug',
        'discovery',
    ] as $id) {
        expect($ids)->toContain($id);
    }
});

test('every result carries id, label, status and an actionable message', function () {
    bootFixtureKernel();

    foreach (Doctor::run() as $result) {
        expect($result)->toHaveKeys(['id', 'label', 'status', 'message'])
            ->and($result['status'])->toBeIn([Doctor::OK, Doctor::INFO, Doctor::WARN, Doctor::FAIL, Doctor::SKIP])
            ->and($result['message'])->not->toBe('');
    }
});

// ------------------------------------------------------------------ app_key

test('a healthy APP_KEY (>= 32 bytes) passes', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'app_key')['status'])->toBe(Doctor::OK);
});

test('a short APP_KEY is a critical failure', function () {
    bootFixtureKernel();
    $restore = doctorSetEnv('APP_KEY', 'too-short');

    try {
        $check = doctorCheck(Doctor::run(), 'app_key');

        expect($check['status'])->toBe(Doctor::FAIL)
            ->and($check['message'])->toContain('32');
    } finally {
        $restore();
    }
});

test('a missing APP_KEY is a critical failure', function () {
    bootFixtureKernel();
    $restore = doctorSetEnv('APP_KEY', null);

    try {
        expect(doctorCheck(Doctor::run(), 'app_key')['status'])->toBe(Doctor::FAIL);
    } finally {
        $restore();
    }
});

// ------------------------------------------------------------------ app_url

test('a set app.app_url passes', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'app_url')['status'])->toBe(Doctor::OK);
});

test('an empty app.app_url is a warning (broken generated URLs)', function () {
    bootFixtureKernel();
    config(['app.app_url' => '']);

    $check = doctorCheck(Doctor::run(), 'app_url');

    expect($check['status'])->toBe(Doctor::WARN)
        ->and($check['message'])->toContain('signedRoute');
});

// ------------------------------------------------------------ var/ writable

test('an unwritable var subdirectory is a critical failure', function () {
    bootFixtureKernel();

    $dir = \Ions\Bundles\Path::logs('');

    chmod($dir, 0o000);

    if (is_writable($dir)) {
        // Running as root: chmod has no effect on writability — cannot seed
        // this misconfig, so the scenario is untestable here.
        chmod($dir, 0o755);
        test()->markTestSkipped('Filesystem permissions are not enforced for this user (root?).');
    }

    try {
        $check = doctorCheck(Doctor::run(), 'writable_logs');

        expect($check['status'])->toBe(Doctor::FAIL)
            ->and($check['message'])->toContain('var/logs');
    } finally {
        chmod($dir, 0o755);
    }
});

test('writable var directories pass', function () {
    bootFixtureKernel();

    $results = Doctor::run();

    foreach (['writable_var', 'writable_cache', 'writable_logs', 'writable_templates'] as $id) {
        expect(doctorCheck($results, $id)['status'])->toBe(Doctor::OK);
    }
});

// ----------------------------------------------------------------- database

test('db engine configured and reachable passes with a trivial query', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'db')['status'])->toBe(Doctor::OK);
});

test('db engine configured but unreachable is a critical failure', function () {
    bootFixtureKernel();

    // Point the capsule's default connection at a sqlite file inside a
    // directory that cannot exist — PDO fails instantly (no network timeout).
    Kernel::app()->get('db')->addConnection([
        'driver' => 'sqlite',
        'database' => '/nonexistent-doctor-dir/' . uniqid('', true) . '/db.sqlite',
        'prefix' => '',
    ], 'default');

    $check = doctorCheck(Doctor::run(), 'db');

    expect($check['status'])->toBe(Doctor::FAIL);
});

test('no db engine configured is skipped, not failed', function () {
    bootFixtureKernel();
    config(['app.database_engine' => []]);

    expect(doctorCheck(Doctor::run(), 'db')['status'])->toBe(Doctor::SKIP);
});

// --------------------------------------------------------- security posture

test('explicit cookie_secure=false override is a warning', function () {
    bootFixtureKernel(); // fixture session.php sets cookie_secure => false explicitly

    $check = doctorCheck(Doctor::run(), 'session_cookies');

    expect($check['status'])->toBe(Doctor::WARN)
        ->and($check['message'])->toContain('cookie_secure');
});

test('secure session cookie flags pass', function () {
    bootFixtureKernel();
    config(['session.cookie_secure' => true]);

    expect(doctorCheck(Doctor::run(), 'session_cookies')['status'])->toBe(Doctor::OK);
});

test('wildcard CORS origin is a warning', function () {
    bootFixtureKernel();
    config(['app.cors.origins' => ['*']]);

    expect(doctorCheck(Doctor::run(), 'cors')['status'])->toBe(Doctor::WARN);
});

test('deny-by-default CORS (no origins) passes', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'cors')['status'])->toBe(Doctor::OK);
});

test('disabled CSRF protection is a warning', function () {
    bootFixtureKernel();
    config(['app.csrf.enabled' => false]);

    $check = doctorCheck(Doctor::run(), 'csrf');

    expect($check['status'])->toBe(Doctor::WARN)
        ->and($check['message'])->toContain('csrf');
});

test('CSRF enabled (the default) passes', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'csrf')['status'])->toBe(Doctor::OK);
});

test('empty trusted_hosts is a warning', function () {
    bootFixtureKernel();

    expect(doctorCheck(Doctor::run(), 'trusted_hosts')['status'])->toBe(Doctor::WARN);
});

test('configured trusted_hosts passes', function () {
    bootFixtureKernel();
    config(['app.trusted_hosts' => ['localhost']]);

    expect(doctorCheck(Doctor::run(), 'trusted_hosts')['status'])->toBe(Doctor::OK);
});

test('APP_DEBUG enabled is a warning', function () {
    bootFixtureKernel();
    $restore = doctorSetEnv('APP_DEBUG', 'true');

    try {
        expect(doctorCheck(Doctor::run(), 'debug')['status'])->toBe(Doctor::WARN);
    } finally {
        $restore();
    }
});

// ------------------------------------------------------------- cache states

test('absent caches are informational, not warnings', function () {
    bootFixtureKernel(); // the app fixture carries no route/config/provider caches

    $results = Doctor::run();

    foreach (['route_cache', 'config_cache', 'providers_cache'] as $id) {
        $check = doctorCheck($results, $id);

        expect($check['status'])->toBe(Doctor::INFO)
            ->and($check['message'])->toContain('optimize');
    }
});

// --------------------------------------------------------------- discovery

test('an explicit empty app.providers array is a warning (zero providers would register)', function () {
    bootFixtureKernel();
    // Kernel::bootProviders() treats ANY array — including [] — as explicit
    // mode, so [] boots the app with zero providers. Doctor must say so.
    config(['app.providers' => []]);

    $check = doctorCheck(Doctor::run(), 'discovery');

    expect($check['status'])->toBe(Doctor::WARN)
        ->and($check['message'])->toContain('empty array')
        ->and($check['message'])->toContain('ZERO providers');
});

// ----------------------------------------------------------------- summary

test('summary() tallies results by status', function () {
    $summary = Doctor::summary([
        ['id' => 'a', 'label' => 'A', 'status' => Doctor::OK, 'message' => 'm'],
        ['id' => 'b', 'label' => 'B', 'status' => Doctor::WARN, 'message' => 'm'],
        ['id' => 'c', 'label' => 'C', 'status' => Doctor::FAIL, 'message' => 'm'],
        ['id' => 'd', 'label' => 'D', 'status' => Doctor::INFO, 'message' => 'm'],
        ['id' => 'e', 'label' => 'E', 'status' => Doctor::SKIP, 'message' => 'm'],
        ['id' => 'f', 'label' => 'F', 'status' => Doctor::OK, 'message' => 'm'],
    ]);

    expect($summary)->toBe(['ok' => 2, 'info' => 1, 'warn' => 1, 'fail' => 1, 'skip' => 1]);
});

test('hasFailures() is true only when a FAIL is present', function () {
    $ok = [['id' => 'a', 'label' => 'A', 'status' => Doctor::WARN, 'message' => 'm']];
    $bad = [['id' => 'a', 'label' => 'A', 'status' => Doctor::FAIL, 'message' => 'm']];

    expect(Doctor::hasFailures($ok))->toBeFalse()
        ->and(Doctor::hasFailures($bad))->toBeTrue();
});
