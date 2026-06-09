<?php

use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Auth\Providers\SentinelUserProvider;
use Ions\Foundation\Kernel;
use Ions\Providers\AuthProvider;

beforeEach(fn () => bootFixtureKernel());

test('auth.provider = eloquent resolves an EloquentUserProvider', function () {
    config(['auth.provider' => 'eloquent']);

    $app = Kernel::app();
    (new AuthProvider($app))->register();

    expect($app->get('user_provider'))->toBeInstanceOf(EloquentUserProvider::class);
});

test('auth.provider = sentinel resolves a SentinelUserProvider', function () {
    config(['auth.provider' => 'sentinel']);

    $app = Kernel::app();
    (new AuthProvider($app))->register();

    expect($app->get('user_provider'))->toBeInstanceOf(SentinelUserProvider::class);
});

test('auth.provider defaults to sentinel when config key is absent', function () {
    // Ensure the key is not set.
    config(['auth.provider' => null]);

    $app = Kernel::app();
    (new AuthProvider($app))->register();

    expect($app->get('user_provider'))->toBeInstanceOf(SentinelUserProvider::class);
});

test('auth.provider accepts an FQCN class-string', function () {
    config(['auth.provider' => EloquentUserProvider::class]);

    $app = Kernel::app();
    (new AuthProvider($app))->register();

    expect($app->get('user_provider'))->toBeInstanceOf(EloquentUserProvider::class);
});

test('auth.provider falls back to sentinel for an unknown string', function () {
    config(['auth.provider' => 'nonexistent_driver']);

    $app = Kernel::app();
    (new AuthProvider($app))->register();

    expect($app->get('user_provider'))->toBeInstanceOf(SentinelUserProvider::class);
});
