<?php

use Ions\Security\ArrayRevocationStore;
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
