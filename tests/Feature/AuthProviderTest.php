<?php

use Ions\Foundation\Kernel;
use Ions\Providers\AuthProvider;
use Ions\Security\Jwt;

beforeEach(fn () => bootFixtureKernel());

test('AuthProvider binds a jwt service that round-trips a token', function () {
    $app = Kernel::app();
    (new AuthProvider($app))->register();
    expect($app->has('jwt'))->toBeTrue();
    /** @var Jwt $jwt */
    $jwt = $app->get('jwt');
    expect($jwt)->toBeInstanceOf(Jwt::class);
    $token = $jwt->issue('u-1');
    expect($jwt->verify($token)->userId)->toBe('u-1');
});
