<?php

use Ions\Foundation\Kernel;

test('the db engine still works after RedBean removal', function () {
    bootFixtureKernel();
    expect(Kernel::app()->has('db'))->toBeTrue();
    expect(\Ions\Support\DB::connection()->select('select 1 as one')[0]->one)->toBe(1);
});

test('a redbean engine value is a no-op and does not fatal', function () {
    // Boot a fixture kernel so the app container exists.
    bootFixtureKernel();
    $provider = new \Ions\Providers\DatabaseProvider(Kernel::app());
    // Simulate the engine containing both 'db' and 'redbean':
    // the provider must NOT try to use RedBean (which is gone) — it logs a deprecation and continues.
    config(['app.database_engine' => ['db', 'redbean']]);
    // Re-register so the db binding exists, then boot with the redbean flag present.
    $provider->register();
    expect(fn () => $provider->boot())->not->toThrow(\Throwable::class);
});
