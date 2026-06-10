<?php

/**
 * Proves that DatabaseProvider:
 *   1. Does NOT open a PDO connection at boot time (lazy connection).
 *   2. Gates query logging behind the EXPLICIT config('database.query_log')
 *      flag — APP_DEBUG alone no longer enables it (the log accumulates
 *      unbounded in memory; debuggers must opt in). See UPGRADE-4.1.
 */

use Ions\Foundation\Kernel;
use Ions\Support\DB;

beforeEach(fn () => bootFixtureKernel());

test('the database connection does not open a PDO until the first query', function () {
    $connection = Kernel::app()->get('db')->getConnection(); // default connection

    // ConnectionFactory always stores a Closure as the raw PDO until the first call.
    // getRawPdo() returns the unresolved value (a Closure) — NOT a \PDO instance yet.
    expect($connection->getRawPdo())->not->toBeInstanceOf(\PDO::class);

    // Executing the first query causes the Closure to be resolved into a real \PDO.
    DB::connection()->select('select 1 as one');

    expect($connection->getRawPdo())->toBeInstanceOf(\PDO::class);
});

test('query logging is OFF by default', function () {
    DB::connection()->select('select 1 as one');
    expect(DB::connection()->logging())->toBeFalse();
});

test('APP_DEBUG alone does NOT enable query logging (behavior change in 4.1)', function () {
    $originalDebug = getenv('APP_DEBUG');

    putenv('APP_DEBUG=1');
    $_ENV['APP_DEBUG'] = '1';

    try {
        // Re-boot so DatabaseProvider::boot() runs again under APP_DEBUG.
        bootFixtureKernel();

        DB::connection()->select('select 1 as one');
        expect(DB::connection()->logging())->toBeFalse();
    } finally {
        // Restore the original APP_DEBUG value to avoid leaking state.
        if ($originalDebug === false) {
            putenv('APP_DEBUG');   // unset
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
    }
});

test('query logging is ON when config database.query_log is explicitly true', function () {
    config(['database.query_log' => true]);

    // Re-run the provider boot so it re-reads the flag (idempotent bindings).
    (new \Ions\Providers\DatabaseProvider(Kernel::app()))->boot();

    DB::connection()->select('select 1 as one');
    expect(DB::connection()->logging())->toBeTrue();
});
