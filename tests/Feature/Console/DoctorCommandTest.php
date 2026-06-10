<?php

declare(strict_types=1);

use Ions\commands\DoctorCommand;
use Ions\Foundation\Doctor;

/*
|--------------------------------------------------------------------------
| ions doctor — console command (Task 8.6c)
|--------------------------------------------------------------------------
| Command-level coverage: exit codes (SUCCESS on healthy host even with
| warnings; FAILURE on any critical misconfig), the human summary line and
| the CI-friendly --json output.
*/

/**
 * Mutate APP_KEY across every channel env() may read and return a restorer.
 */
function doctorCommandSetAppKey(?string $value): Closure
{
    $key = 'APP_KEY';
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

test('doctor exits SUCCESS on a healthy host (warnings do not fail)', function () {
    bootFixtureKernel();

    $tester = runConsoleCommand(new DoctorCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('ok,')
        ->and($tester->getDisplay())->toMatch('/\d+ ok, \d+ warnings, \d+ failures/');
});

test('doctor exits FAILURE on a seeded critical misconfig (short APP_KEY)', function () {
    bootFixtureKernel();
    $restore = doctorCommandSetAppKey('short');

    try {
        $tester = runConsoleCommand(new DoctorCommand());

        expect($tester->getStatusCode())->toBe(1)
            ->and($tester->getDisplay())->toContain('APP_KEY');
    } finally {
        $restore();
    }
});

test('doctor --json emits parseable structured results', function () {
    bootFixtureKernel();

    $tester = runConsoleCommand(new DoctorCommand(), ['--json' => true]);

    $payload = json_decode($tester->getDisplay(), true);

    expect($tester->getStatusCode())->toBe(0)
        ->and($payload)->toBeArray()
        ->and($payload)->toHaveKeys(['checks', 'summary', 'ok']);

    $ids = array_column($payload['checks'], 'id');

    foreach (['app_key', 'writable_var', 'db', 'csrf', 'cors', 'debug'] as $id) {
        expect($ids)->toContain($id);
    }

    expect($payload['ok'])->toBeTrue()
        ->and($payload['summary'])->toHaveKeys(['ok', 'info', 'warn', 'fail', 'skip']);
});

test('doctor --json reports ok=false and still parses on a failing host', function () {
    bootFixtureKernel();
    $restore = doctorCommandSetAppKey(null);

    try {
        $tester = runConsoleCommand(new DoctorCommand(), ['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true);

        expect($tester->getStatusCode())->toBe(1)
            ->and($payload['ok'])->toBeFalse()
            ->and($payload['summary']['fail'])->toBeGreaterThan(0);
    } finally {
        $restore();
    }
});

test('doctor is registered as a framework command in the console Kernel', function () {
    $kernel = Ions\Console\Kernel::boot(dirname(__DIR__, 2) . '/fixtures/app');

    expect($kernel->getApplication()->has('doctor'))->toBeTrue();
});

test('doctor prints one row per check with its status', function () {
    bootFixtureKernel();

    $tester = runConsoleCommand(new DoctorCommand());
    $display = $tester->getDisplay();

    foreach (Doctor::run() as $check) {
        expect($display)->toContain($check['id']);
    }
});
