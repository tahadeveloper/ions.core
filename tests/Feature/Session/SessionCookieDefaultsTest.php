<?php

declare(strict_types=1);

/**
 * D8-1 default flip: the native-driver cookie options are secure by default.
 * Omitting the cookie_* keys from config/session.php must yield
 * httponly + samesite=lax + secure cookies (previously: raw PHP defaults).
 *
 * These tests exercise the OPTIONS construction only — no native session is
 * ever started (CLI-safe).
 */

use Ions\Session\SessionManager;

test('empty config yields secure-by-default native cookie options', function () {
    $s = new SessionManager(['driver' => 'array']);
    $options = $s->nativeOptions();

    expect($options['cookie_httponly'])->toBeTrue()
        ->and($options['cookie_samesite'])->toBe('lax')
        ->and($options['cookie_secure'])->toBeTrue();
});

test('explicit config overrides each secure default', function () {
    $s = new SessionManager([
        'driver' => 'array',
        'cookie_secure' => false,
        'cookie_httponly' => false,
        'cookie_samesite' => 'strict',
    ]);
    $options = $s->nativeOptions();

    expect($options['cookie_secure'])->toBeFalse()
        ->and($options['cookie_httponly'])->toBeFalse()
        ->and($options['cookie_samesite'])->toBe('strict');
});

test("cookie_secure => 'auto' resolves from the current request scheme", function () {
    // bootFixtureKernel() captures a plain-HTTP CLI request -> not secure.
    bootFixtureKernel();
    $s = new SessionManager(['driver' => 'array', 'cookie_secure' => 'auto']);
    expect($s->nativeOptions()['cookie_secure'])->toBeFalse();
});

test("cookie_secure => 'auto' fails secure (true) when no request is available", function () {
    \Ions\Foundation\Kernel::resetForTesting();
    $s = new SessionManager(['driver' => 'array', 'cookie_secure' => 'auto']);
    expect($s->nativeOptions()['cookie_secure'])->toBeTrue();
});

test('name and lifetime keys are still mapped through', function () {
    $s = new SessionManager(['driver' => 'array', 'name' => 'my_sess', 'lifetime' => 120]);
    $options = $s->nativeOptions();
    expect($options['name'])->toBe('my_sess')
        ->and($options['cookie_lifetime'])->toBe(120);
});
