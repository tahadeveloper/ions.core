<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Auth\TwoFactor;
use Ions\Auth\TwoFactorReplayStore;

test('a code can only be consumed once per user/step', function () {
    $store = new TwoFactorReplayStore(new Repository(new ArrayStore()));
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;
    $code = TwoFactor::code($secret, $now);

    expect($store->verifyOnce('user-1', $secret, $code, 1, $now))->toBeTrue()
        ->and($store->verifyOnce('user-1', $secret, $code, 1, $now))->toBeFalse();
});

test('replay tracking is scoped per user', function () {
    $store = new TwoFactorReplayStore(new Repository(new ArrayStore()));
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;
    $code = TwoFactor::code($secret, $now);

    expect($store->verifyOnce('user-1', $secret, $code, 1, $now))->toBeTrue()
        ->and($store->verifyOnce('user-2', $secret, $code, 1, $now))->toBeTrue();
});

test('a wrong code is rejected and does not burn a step', function () {
    $store = new TwoFactorReplayStore(new Repository(new ArrayStore()));
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;

    expect($store->verifyOnce('user-1', $secret, '000000', 1, $now))->toBeFalse();

    // The genuine current code still works afterwards.
    $code = TwoFactor::code($secret, $now);
    expect($store->verifyOnce('user-1', $secret, $code, 1, $now))->toBeTrue();
});

test('a drifted (previous-step) code is accepted once then blocked on replay', function () {
    $store = new TwoFactorReplayStore(new Repository(new ArrayStore()));
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;
    $prev = TwoFactor::code($secret, $now - 30);

    expect($store->verifyOnce('user-1', $secret, $prev, 1, $now))->toBeTrue()
        ->and($store->verifyOnce('user-1', $secret, $prev, 1, $now))->toBeFalse();
});
