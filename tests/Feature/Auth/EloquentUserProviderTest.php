<?php

use Illuminate\Database\Schema\Blueprint;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Providers\EloquentUserAdapter;
use Ions\Auth\Providers\EloquentUserProvider;
use Ions\Foundation\Kernel;
use Ions\Support\DB;

beforeEach(function () {
    bootFixtureKernel();

    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('users');
    $schema->create('users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('email')->unique();
        $t->string('password');
    });

    DB::connection()->table('users')->insert([
        'email'    => 'a@b.c',
        'password' => password_hash('secret', PASSWORD_BCRYPT),
    ]);
});

test('retrieveById returns an Authenticatable for a known id', function () {
    $provider = new EloquentUserProvider();
    $user = $provider->retrieveById(1);

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->toBe(1)
        ->and($user->getAuthIdentifierName())->toBe('id');
});

test('retrieveById returns null for an unknown id', function () {
    $provider = new EloquentUserProvider();

    expect($provider->retrieveById(999))->toBeNull();
});

test('retrieveByCredentials returns user for matching email', function () {
    $provider = new EloquentUserProvider();
    $user = $provider->retrieveByCredentials(['email' => 'a@b.c']);

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->toBe(1);
});

test('retrieveByCredentials returns null for wrong email', function () {
    $provider = new EloquentUserProvider();

    expect($provider->retrieveByCredentials(['email' => 'unknown@x.com']))->toBeNull();
});

test('validateCredentials returns true for correct password', function () {
    $provider = new EloquentUserProvider();
    $user = $provider->retrieveById(1);

    expect($provider->validateCredentials($user, ['password' => 'secret']))->toBeTrue();
});

test('validateCredentials returns false for wrong password', function () {
    $provider = new EloquentUserProvider();
    $user = $provider->retrieveById(1);

    expect($provider->validateCredentials($user, ['password' => 'wrong']))->toBeFalse();
});

test('EloquentUserAdapter exposes getAuthPassword', function () {
    $provider = new EloquentUserProvider();
    /** @var EloquentUserAdapter $user */
    $user = $provider->retrieveById(1);

    expect($user)->toBeInstanceOf(EloquentUserAdapter::class)
        ->and(password_verify('secret', $user->getAuthPassword()))->toBeTrue();
});
