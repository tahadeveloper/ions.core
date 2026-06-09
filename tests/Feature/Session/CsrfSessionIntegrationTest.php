<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

beforeEach(fn () => bootFixtureKernel());

test('the default csrf manager stores tokens in the framework session', function () {
    // Start the framework session (array driver in tests).
    Kernel::app()->get('session')->start();

    // The default csrf manager must be backed by the session token storage.
    $manager = Kernel::app()->get('csrf');

    // Generating a token writes it into the shared framework session.
    $token = $manager->getToken('web')->getValue();
    expect($token)->toBeString()->not->toBe('');

    // The framework session now holds the CSRF namespace key (the stored,
    // unmasked value differs from the randomized token string returned above).
    $session = Kernel::app()->get('session')->getSession();
    $key = SessionTokenStorage::SESSION_NAMESPACE . '/web';
    expect($session->has($key))->toBeTrue()
        ->and($session->get($key))->toBeString()->not->toBe('');
});

test('a token issued via the default csrf manager validates', function () {
    Kernel::app()->get('session')->start();
    $manager = Kernel::app()->get('csrf');
    $token = $manager->getToken('web')->getValue();

    expect($manager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('web', $token)))->toBeTrue();
});

test('a token from the default session-backed manager passes the real web pipeline', function () {
    // No binding swap: csrfToken() resolves the container-bound, session-backed manager.
    $token = csrfToken();

    $request = \Ions\Support\Request::create('/csrf-protected', 'POST', ['_ion_token' => $token]);
    expect(Kernel::handle($request)->getStatusCode())->toBe(200);
});
