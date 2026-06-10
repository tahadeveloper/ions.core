<?php

declare(strict_types=1);

/**
 * Per-(email+IP) throttle on the password-forgot endpoint: tighter than the
 * route-level throttle, keyed on the requested email so an attacker cannot
 * hammer reset-code generation for a single account. Default: 3 attempts per
 * 600 s (config app.auth.forgot_throttle = ['max' => 3, 'decay' => 600]).
 * Throttled requests get a 429 with the same generic message shape.
 */

use Ions\Foundation\Kernel;
use Ions\Support\Request;

beforeEach(function () {
    bootFixtureKernel();

    // Password reset requires a provider supporting it -> Sentinel on SQLite.
    config(['auth.provider' => 'sentinel']);
    Kernel::app()->forgetInstance('user_provider');
    createSentinelTables();

    \Cartalyst\Sentinel\Native\Facades\Sentinel::registerAndActivate([
        'email'    => 'throttle-me@example.com',
        'password' => 'password-1',
    ]);
    \Cartalyst\Sentinel\Native\Facades\Sentinel::registerAndActivate([
        'email'    => 'other@example.com',
        'password' => 'password-2',
    ]);
});

function forgotPost(string $email): Request
{
    $request = Request::create('/api/auth/password/forgot', 'POST', [], [], [], [], json_encode(['email' => $email]) ?: '{}');
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

test('the 4th forgot request for the same email is throttled with 429', function () {
    for ($i = 1; $i <= 3; $i++) {
        $response = Kernel::handle(forgotPost('throttle-me@example.com'));
        expect($response->getStatusCode())->toBe(200);
    }

    $fourth = Kernel::handle(forgotPost('throttle-me@example.com'));
    expect($fourth->getStatusCode())->toBe(429)
        ->and($fourth->headers->get('Retry-After'))->not->toBeNull();
});

test('a different email is unaffected by another email being throttled', function () {
    for ($i = 1; $i <= 4; $i++) {
        Kernel::handle(forgotPost('throttle-me@example.com'));
    }

    $other = Kernel::handle(forgotPost('other@example.com'));
    expect($other->getStatusCode())->toBe(200);
});

test('app.auth.forgot_throttle config overrides max attempts', function () {
    config(['app.auth.forgot_throttle' => ['max' => 1, 'decay' => 600]]);

    expect(Kernel::handle(forgotPost('throttle-me@example.com'))->getStatusCode())->toBe(200)
        ->and(Kernel::handle(forgotPost('throttle-me@example.com'))->getStatusCode())->toBe(429);
});
