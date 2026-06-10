<?php

declare(strict_types=1);

/**
 * Session fixation hardening: a successful credential login regenerates the
 * session id (when a session exists and is started, i.e. a web-originated
 * login) while keeping session data. Stateless API logins without a started
 * session are unaffected.
 */

use Ions\Foundation\Kernel;
use Ions\Session\SessionManager;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

function regenJsonPost(string $path, array $body = []): Request
{
    $request = Request::create($path, 'POST', [], [], [], [], json_encode($body) ?: '{}');
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

test('successful login regenerates a started session id and keeps its data', function () {
    /** @var SessionManager $session */
    $session = Kernel::app()->get('session');
    $session->start();
    $session->put('cart', 'kept');
    $preLoginId = $session->getId();

    $response = Kernel::handle(regenJsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($session->getId())->not->toBe($preLoginId)   // id rotated
        ->and($session->get('cart'))->toBe('kept');        // data survives
});

test('failed login does NOT regenerate the session id', function () {
    /** @var SessionManager $session */
    $session = Kernel::app()->get('session');
    $session->start();
    $preLoginId = $session->getId();

    $response = Kernel::handle(regenJsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'wrong-password',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and($session->getId())->toBe($preLoginId);
});

test('stateless login without a started session still succeeds', function () {
    /** @var SessionManager $session */
    $session = Kernel::app()->get('session');
    expect($session->isStarted())->toBeFalse();

    $response = Kernel::handle(regenJsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($session->isStarted())->toBeFalse();
});
