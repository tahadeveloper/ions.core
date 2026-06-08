<?php

use Ions\Foundation\Kernel;
use Ions\Providers\DatabaseProvider;

test('DatabaseProvider binds an eloquent-capable db manager', function () {
    bootFixtureKernel();
    $app = Kernel::app();
    // simulate what Kernel bootstrap will do in 2.2.4 (provider not yet auto-run)
    $provider = new DatabaseProvider($app);
    $provider->register();
    $provider->boot();
    expect($app->has('db'))->toBeTrue();
    $result = \Ions\Support\DB::connection()->select('select 1 as one');
    expect($result[0]->one)->toBe(1);
});

test('RegisterDB::boot still works as a BC shim (idempotent)', function () {
    bootFixtureKernel();
    \Ions\Foundation\RegisterDB::boot();
    \Ions\Foundation\RegisterDB::boot(); // second call must not error or double-bind-fatal
    expect(Kernel::app()->has('db'))->toBeTrue();
    $result = \Ions\Support\DB::connection()->select('select 1 as one');
    expect($result[0]->one)->toBe(1);
});
