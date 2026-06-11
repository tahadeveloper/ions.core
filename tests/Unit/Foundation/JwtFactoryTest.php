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

test('build() returns null when APP_KEY is shorter than 32 bytes', function () {
    // Snapshot every env source env() reads (getenv / $_ENV / $_SERVER), set a
    // sub-32-byte key, then restore — keeping the fixture .env key untouched for
    // sibling tests.
    $snapshot = [
        'getenv' => getenv('APP_KEY'),
        'env' => $_ENV['APP_KEY'] ?? null,
        'server' => $_SERVER['APP_KEY'] ?? null,
    ];

    $short = str_repeat('a', 31); // 31 bytes < 32-byte minimum
    putenv('APP_KEY=' . $short);
    $_ENV['APP_KEY'] = $short;
    $_SERVER['APP_KEY'] = $short;

    try {
        expect(JwtFactory::build(Kernel::app()))->toBeNull();
    } finally {
        $snapshot['getenv'] === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $snapshot['getenv']);
        if ($snapshot['env'] === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $snapshot['env'];
        }
        if ($snapshot['server'] === null) {
            unset($_SERVER['APP_KEY']);
        } else {
            $_SERVER['APP_KEY'] = $snapshot['server'];
        }
    }
});
