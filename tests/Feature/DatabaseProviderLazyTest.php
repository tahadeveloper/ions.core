<?php

/**
 * Proves that DatabaseProvider:
 *   1. Does NOT open a PDO connection at boot time (lazy connection).
 *   2. Gates query logging behind env('APP_DEBUG'), not config('app.app_debug').
 *   3. Re-booting with APP_DEBUG on enables query logging.
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

test('query logging is OFF by default when APP_DEBUG is not set', function () {
    // The fixture .env has no APP_DEBUG key → env('APP_DEBUG') returns false → logging stays off.
    DB::connection()->select('select 1 as one');
    expect(DB::connection()->logging())->toBeFalse();
});

test('query logging is ON when APP_DEBUG is set to a truthy value', function () {
    $originalDebug = getenv('APP_DEBUG');

    putenv('APP_DEBUG=1');
    $_ENV['APP_DEBUG'] = '1';

    try {
        // Re-boot so DatabaseProvider::boot() runs again and reads the updated env.
        bootFixtureKernel();

        DB::connection()->select('select 1 as one');
        expect(DB::connection()->logging())->toBeTrue();
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
