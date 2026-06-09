<?php

use Ions\Security\ArrayRevocationStore;
use Ions\Security\Jwt;
use Ions\Security\TokenException;

test('issueRefresh creates a refresh token that refresh() exchanges for a new access token', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $refresh = $jwt->issueRefresh('42');
    $newAccess = $jwt->refresh($refresh);
    expect($jwt->verify($newAccess)->userId)->toBe('42');
});

test('an access token cannot be used as a refresh token', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $access = $jwt->issue('42');
    expect(fn () => $jwt->refresh($access))->toThrow(TokenException::class);
});

test('a refresh token cannot be used as an access token (verify rejects typ=refresh)', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $refresh = $jwt->issueRefresh('42');
    expect(fn () => $jwt->verify($refresh))->toThrow(TokenException::class);
});

test('refresh rotates: the old refresh token is revoked after use', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $refresh = $jwt->issueRefresh('42');
    $jwt->refresh($refresh);
    expect(fn () => $jwt->refresh($refresh))->toThrow(TokenException::class); // old refresh now revoked
});

test('caller cannot override typ to forge a refresh token via issue()', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $sneaky = $jwt->issue('42', ['typ' => 'refresh']); // attempt to forge a refresh token
    // it must remain an ACCESS token: verify() accepts it, refresh() rejects it
    expect($jwt->verify($sneaky)->userId)->toBe('42');
    expect(fn () => $jwt->refresh($sneaky))->toThrow(TokenException::class);
});
