<?php

use Ions\Foundation\Kernel;
use Ions\Providers\ConfigProvider;

beforeEach(fn () => bootFixtureKernel());

test('ConfigProvider binds the Config object as the container "config" service', function () {
    $app = Kernel::app();
    (new ConfigProvider($app))->register();
    expect($app->has('config'))->toBeTrue()
        ->and($app->get('config'))->toBe(Kernel::config()) // same instance
        ->and($app->get('config')->get('app.name'))->toBe('IonsFixture');
});
