<?php

declare(strict_types=1);

use Ions\Foundation\JwtFactory;
use Ions\Foundation\Kernel;
use Ions\Security\ArrayRevocationStore;
use Ions\Security\Jwt;

/*
|--------------------------------------------------------------------------
| JwtFactory collaborator (Phase 12.7 extraction)
|--------------------------------------------------------------------------
| Pure unit coverage for the factory extracted out of Kernel::buildJwt().
| The container-binding integration is already exercised by AuthProvider and
| the auth feature suites; here we drive the factory directly.
*/

beforeEach(function () {
    bootFixtureKernel();
});

test('build() returns a Jwt when APP_KEY is long enough', function () {
    // The fixture .env carries a 64-char APP_KEY.
    expect(JwtFactory::build(Kernel::app()))->toBeInstanceOf(Jwt::class);
});

test('build() uses the container revocation_store binding when present', function () {
    $store = new ArrayRevocationStore();
    Kernel::app()->instance('revocation_store', $store);

    try {
        $jwt = JwtFactory::build(Kernel::app());
        expect($jwt)->toBeInstanceOf(Jwt::class);
    } finally {
        Kernel::app()->forgetInstance('revocation_store');
    }
});

test('build() with a null container still produces a Jwt (no store wired)', function () {
    expect(JwtFactory::build(null))->toBeInstanceOf(Jwt::class);
});
