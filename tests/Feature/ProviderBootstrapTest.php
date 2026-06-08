<?php

use Ions\Foundation\Kernel;

test('default providers are registered and booted during Kernel::boot', function () {
    bootFixtureKernel();
    $app = Kernel::app();
    expect($app->has('filesystem'))->toBeTrue()
        ->and($app->has('db'))->toBeTrue(); // DatabaseProvider ran at boot, no controller needed
});

test('db works immediately after boot without instantiating a controller', function () {
    bootFixtureKernel();
    $result = \Ions\Support\DB::connection()->select('select 1 as one');
    expect($result[0]->one)->toBe(1);
});
