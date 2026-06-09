<?php

/**
 * Guard integration tests — exercises the static Guard facade end-to-end
 * against a real Sentinel-on-SQLite in-memory database.
 *
 * Coverage: login success, wrong password, unknown user, inactive user,
 * and the full password-reset flow (forgetCode → completeReset).
 *
 * The createSentinelTables() helper is defined in tests/Pest.php and shared
 * with SentinelUserProviderTest to avoid duplication.
 */

use Cartalyst\Sentinel\Native\Facades\Sentinel;
use Ions\Auth\Guard\Guard;

beforeEach(function () {
    bootFixtureKernel();
    createSentinelTables();
});

test('login succeeds for a registered + activated user with correct credentials', function () {
    Sentinel::registerAndActivate(['email' => 'a@b.c', 'password' => 'secret']);

    expect(Guard::login(['email' => 'a@b.c', 'password' => 'secret']))->toBeTrue();
});

test('login fails with wrong password (error_no 3)', function () {
    Sentinel::registerAndActivate(['email' => 'a@b.c', 'password' => 'secret']);

    $result = Guard::login(['email' => 'a@b.c', 'password' => 'WRONG']);

    expect($result)->toMatchArray(['error_no' => 3]);
});

test('login fails for unknown user (error_no 1)', function () {
    expect(Guard::login(['email' => 'nobody@x.y', 'password' => 'x']))->toMatchArray(['error_no' => 1]);
});

test('login fails for a registered-but-NOT-activated user (error_no 2)', function () {
    Sentinel::register(['email' => 'inactive@b.c', 'password' => 'secret']); // no activation

    expect(Guard::login(['email' => 'inactive@b.c', 'password' => 'secret']))->toMatchArray(['error_no' => 2]);
});

test('password reset flow: forgetCode issues a code, completeReset changes the password', function () {
    Sentinel::registerAndActivate(['email' => 'a@b.c', 'password' => 'old-pass']);

    $code = Guard::forgetCode(['email' => 'a@b.c']);
    expect($code)->toBeString()->not->toBeEmpty();

    $user = Sentinel::findByCredentials(['email' => 'a@b.c']);
    Guard::completeReset($user->getUserId(), $code, 'new-pass');

    // Old password no longer works.
    expect(Guard::login(['email' => 'a@b.c', 'password' => 'old-pass']))->toMatchArray(['error_no' => 3]);

    // New password works.
    expect(Guard::login(['email' => 'a@b.c', 'password' => 'new-pass']))->toBeTrue();
});
