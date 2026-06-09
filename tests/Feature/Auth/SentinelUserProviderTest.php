<?php

/**
 * SentinelUserProvider integration tests.
 *
 * Strategy: REAL Sentinel tables created on the in-memory SQLite fixture DB.
 * We build the schema via the query-builder schema API (mirroring the Sentinel
 * migration) and then use Sentinel::registerAndActivate() to seed a user.
 * This gives us genuine Sentinel-backed coverage (no skips, no mocks).
 */

use Illuminate\Database\Schema\Blueprint;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Providers\SentinelUserAdapter;
use Ions\Auth\Providers\SentinelUserProvider;
use Ions\Foundation\Kernel;

/**
 * Creates all Sentinel tables on the in-memory SQLite connection.
 * InnoDB engine declarations in the original migration are ignored by SQLite.
 */
function createSentinelTables(): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();

    // Drop in reverse FK order to keep things clean across test runs.
    foreach (['throttle', 'role_users', 'roles', 'reminders', 'persistences', 'activations', 'users'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('email')->unique();
        $t->string('password');
        $t->text('permissions')->nullable();
        $t->timestamp('last_login')->nullable();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->timestamps();
    });

    $schema->create('activations', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->boolean('completed')->default(0);
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    $schema->create('persistences', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->timestamps();
        $t->unique('code');
    });

    $schema->create('reminders', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->boolean('completed')->default(0);
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    $schema->create('roles', function (Blueprint $t) {
        $t->increments('id');
        $t->string('slug')->unique();
        $t->string('name');
        $t->text('permissions')->nullable();
        $t->timestamps();
    });

    $schema->create('role_users', function (Blueprint $t) {
        $t->integer('user_id')->unsigned();
        $t->integer('role_id')->unsigned();
        $t->nullableTimestamps();
        $t->primary(['user_id', 'role_id']);
    });

    $schema->create('throttle', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned()->nullable();
        $t->string('type');
        $t->string('ip')->nullable();
        $t->timestamps();
        $t->index('user_id');
    });
}

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
