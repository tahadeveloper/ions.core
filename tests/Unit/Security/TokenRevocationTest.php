<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Security\ArrayRevocationStore;
use Ions\Security\CacheRevocationStore;
use Ions\Security\Jwt;
use Ions\Security\TokenException;

test('a revoked token is rejected by verify', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $token = $jwt->issue('42');
    expect($jwt->verify($token)->userId)->toBe('42'); // valid before revoke
    $jwt->revoke($token);
    expect(fn () => $jwt->verify($token))->toThrow(TokenException::class); // rejected after
});

test('verify works normally when no revocation store is configured (BC)', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app'); // no store
    $token = $jwt->issue('42');
    expect($jwt->verify($token)->userId)->toBe('42');
});

test('ArrayRevocationStore tracks family revocation separately from per-jti', function () {
    $store = new ArrayRevocationStore();

    expect($store->isFamilyRevoked('fam-1'))->toBeFalse();
    $store->revokeFamily('fam-1', 3600);
    expect($store->isFamilyRevoked('fam-1'))->toBeTrue()
        ->and($store->isFamilyRevoked('fam-2'))->toBeFalse();

    // The family namespace is independent of the per-jti namespace.
    expect($store->isRevoked('fam-1'))->toBeFalse();
});

test('CacheRevocationStore tracks family revocation under a second key namespace', function () {
    $store = new CacheRevocationStore(new Repository(new ArrayStore()));

    expect($store->isFamilyRevoked('fam-1'))->toBeFalse();
    $store->revokeFamily('fam-1', 3600);
    expect($store->isFamilyRevoked('fam-1'))->toBeTrue()
        ->and($store->isFamilyRevoked('fam-2'))->toBeFalse();

    // A revoked family must not collide with the per-jti deny-list key.
    expect($store->isRevoked('fam-1'))->toBeFalse();
});
