<?php

use Ions\Security\Jwt;

beforeEach(function () {
    $this->jwt = new Jwt(secret: str_repeat('a', 32), issuer: 'ions', audience: 'ions-app', ttlSeconds: 3600);
});

test('issues a token bound to a user id', function () {
    $token = $this->jwt->issue(userId: '42');
    $claims = $this->jwt->verify($token);
    expect($claims->userId)->toBe('42');
});

test('rejects a tampered token', function () {
    $token = $this->jwt->issue('42');
    expect(fn () => $this->jwt->verify($token . 'x'))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects an expired token', function () {
    $expired = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', ttlSeconds: -10);
    $token = $expired->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects a token signed with a different secret', function () {
    $other = new Jwt(str_repeat('b', 32), 'ions', 'ions-app', 3600);
    $token = $other->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects a secret shorter than 32 bytes', function () {
    expect(fn () => new Jwt('short', 'ions', 'ions-app'))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects a token issued by a different issuer', function () {
    $other = new Jwt(str_repeat('a', 32), 'someone-else', 'ions-app', 3600);
    $token = $other->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects a token meant for a different audience', function () {
    $other = new Jwt(str_repeat('a', 32), 'ions', 'other-app', 3600);
    $token = $other->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});
