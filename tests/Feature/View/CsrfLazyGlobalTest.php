<?php

declare(strict_types=1);

/**
 * The `_csrf_token` Twig global is LAZY: rendering a template that does NOT
 * output it must leave the session CSRF token UNWRITTEN, so the request stays
 * anonymous and response-cacheable. Rendering a template that DOES output
 * `{{ _csrf_token }}` writes the token (BC) and emits a non-empty value.
 *
 * Regression: previously requestGlobals() seeded `_csrf_token` EAGERLY by
 * calling csrfToken('web') on every env build/refresh, which generated and
 * wrote the token into the session on EVERY render — defeating the 12.5
 * response cache for every framework-rendered page (ResponseCache treats a
 * non-empty session as stateful).
 */

use Ions\Foundation\Kernel;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

beforeEach(fn () => bootFixtureKernel());

/** The session key Symfony's SessionTokenStorage uses for the 'web' token id. */
function csrfSessionKey(): string
{
    return SessionTokenStorage::SESSION_NAMESPACE . '/web';
}

test('rendering a template that does NOT output _csrf_token leaves the session token unwritten', function () {
    $session = Kernel::app()->get('session')->getSession();
    $session->start();
    expect($session->has(csrfSessionKey()))->toBeFalse();

    /** @var \Twig\Environment $env */
    $env = Kernel::app()->get('view.env');
    $out = $env->createTemplate('plain {{ appUrl }}')->render();

    expect($out)->toContain('plain')
        // The lazy global was NEVER stringified, so no token was generated.
        ->and($session->has(csrfSessionKey()))->toBeFalse()
        // And the session carries no data at all -> request stays cacheable.
        ->and($session->all())->toBe([]);
});

test('rendering a template that outputs {{ _csrf_token }} writes the token and emits a non-empty value (BC)', function () {
    $session = Kernel::app()->get('session')->getSession();
    $session->start();
    expect($session->has(csrfSessionKey()))->toBeFalse();

    /** @var \Twig\Environment $env */
    $env = Kernel::app()->get('view.env');
    $out = $env->createTemplate('token=[{{ _csrf_token }}]')->render();

    // BC: the token still renders as a non-empty string.
    expect($out)->toMatch('/token=\[.+\]/')
        ->and($out)->not->toBe('token=[]')
        // Outputting the global generated + stored the session token.
        ->and($session->has(csrfSessionKey()))->toBeTrue();

    // The emitted token validates through the real CSRF manager.
    $token = substr($out, strlen('token=['), -1);
    $manager = Kernel::app()->get('csrf');
    expect($manager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('web', $token)))->toBeTrue();
});

test('_csrf_token is stable within one request and fresh after reset (worker-safe)', function () {
    /** @var \Twig\Environment $env */
    $env = Kernel::app()->get('view.env');

    Kernel::handle(\Ions\Support\Request::create('/ping'));
    $r1a = $env->createTemplate('{{ _csrf_token }}')->render();
    $r1b = $env->createTemplate('{{ _csrf_token }}')->render();
    expect($r1a)->not->toBe('')->and($r1b)->toBe($r1a);

    Kernel::resetForRequest();
    Kernel::handle(\Ions\Support\Request::create('/ping'));
    $r2 = $env->createTemplate('{{ _csrf_token }}')->render();

    expect($r2)->not->toBe('')->and($r2)->not->toBe($r1a);
});
