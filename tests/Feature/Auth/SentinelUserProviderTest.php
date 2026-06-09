<?php

/**
 * SentinelUserProvider integration tests.
 *
 * Strategy: REAL Sentinel tables created on the in-memory SQLite fixture DB.
 * We build the schema via the query-builder schema API (mirroring the Sentinel
 * migration) and then use Sentinel::registerAndActivate() to seed a user.
 * This gives us genuine Sentinel-backed coverage (no skips, no mocks).
 *
 * The createSentinelTables() helper is defined in tests/Pest.php and shared
 * with GuardTest to avoid duplication.
 */

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Providers\SentinelUserAdapter;
use Ions\Auth\Providers\SentinelUserProvider;

beforeEach(function () {
    bootFixtureKernel();
    createSentinelTables();

    // Seed one active user via Sentinel (also creates the activation record).
    \Cartalyst\Sentinel\Native\Facades\Sentinel::registerAndActivate([
        'email'    => 'a@b.c',
        'password' => 'secret',
    ]);
});

test('retrieveById returns an Authenticatable for a known user', function () {
    $provider = new SentinelUserProvider();
    $user = $provider->retrieveById(1);

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->toBe(1)
        ->and($user->getAuthIdentifierName())->toBe('id');
});

test('retrieveById returns null for an unknown id', function () {
    $provider = new SentinelUserProvider();

    expect($provider->retrieveById(9999))->toBeNull();
});

test('retrieveByCredentials returns user for matching email', function () {
    $provider = new SentinelUserProvider();
    $user = $provider->retrieveByCredentials(['email' => 'a@b.c', 'password' => 'secret']);

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->toBe(1);
});

test('retrieveByCredentials returns null for unknown email', function () {
    $provider = new SentinelUserProvider();

    expect($provider->retrieveByCredentials(['email' => 'nobody@x.com', 'password' => 'x']))->toBeNull();
});

test('validateCredentials returns true for correct password', function () {
    $provider = new SentinelUserProvider();
    $user = $provider->retrieveById(1);

    expect($provider->validateCredentials($user, ['password' => 'secret']))->toBeTrue();
});

test('validateCredentials returns false for wrong password', function () {
    $provider = new SentinelUserProvider();
    $user = $provider->retrieveById(1);

    expect($provider->validateCredentials($user, ['password' => 'wrong']))->toBeFalse();
});

test('SentinelUserAdapter exposes the underlying Sentinel user', function () {
    $provider = new SentinelUserProvider();
    /** @var SentinelUserAdapter $user */
    $user = $provider->retrieveById(1);

    expect($user)->toBeInstanceOf(SentinelUserAdapter::class)
        ->and($user->getSentinelUser())->toBeInstanceOf(\Cartalyst\Sentinel\Users\UserInterface::class);
});
