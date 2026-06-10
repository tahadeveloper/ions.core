<?php

/**
 * Phase 8.2 — sequential request isolation within ONE process (no re-boot).
 *
 * Kernel::resetForRequest() must clear per-REQUEST state (request, response,
 * session, per-request Twig globals, query log) while KEEPING boot state
 * (config, container singletons, the 8.1 route memo, the Twig Environment).
 */

use Ions\Foundation\Kernel;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

test('two sequential requests do not share session state', function () {
    // Request 1: handled through the pipeline (StartSessionMiddleware starts
    // the framework session), then writes to the session.
    Kernel::handle(Request::create('/ping'));
    session(['leak' => 'r1']);
    expect(session('leak'))->toBe('r1');

    Kernel::resetForRequest();

    // Request 2: a fresh request must NOT see request 1's session data.
    Kernel::handle(Request::create('/ping'));
    expect(session('leak'))->toBeNull();
});

test('without resetForRequest session state DOES persist (control — proves the leak is real)', function () {
    Kernel::handle(Request::create('/ping'));
    session(['leak' => 'r1']);

    Kernel::handle(Request::create('/ping'));
    expect(session('leak'))->toBe('r1');
});

test('response state does not leak across requests', function () {
    // Request 1's closure mutates the SHARED kernel response (returns null,
    // so the pipeline falls back to Kernel::response()).
    $first = Kernel::handle(Request::create('/leak-header'));
    expect($first->headers->get('X-Leak'))->toBe('r1')
        ->and($first->getContent())->toBe('leaked');

    Kernel::resetForRequest();

    // Request 2 also uses the shared response — it must be a FRESH one.
    $second = Kernel::handle(Request::create('/shared-response'));
    expect($second->headers->has('X-Leak'))->toBeFalse()
        ->and($second->getContent())->toBe('shared')
        ->and($second->getStatusCode())->toBe(200);
});

test('boot state survives reset (config, container, routes — no re-capture)', function () {
    Kernel::handle(Request::create('/ping'));

    $cacheBefore = spl_object_id(Kernel::app()->get('cache'));
    $managerBefore = Kernel::app()->get('session');

    Kernel::resetForRequest();

    // Config and container singletons survive.
    expect(config('app.name'))->toBe('IonsFixture')
        ->and(spl_object_id(Kernel::app()->get('cache')))->toBe($cacheBefore)
        // The SessionManager binding survives (only its inner session is swapped).
        ->and(Kernel::app()->get('session'))->toBe($managerBefore);

    // The 8.1 route memo survives: hide routes/web.php — if reset wrongly
    // dropped the memo, a re-capture would find no routes and /ping would 404.
    $webRoutes = __DIR__ . '/../../fixtures/app/routes/web.php';
    rename($webRoutes, $webRoutes . '.bak');
    try {
        expect(Kernel::handle(Request::create('/ping'))->getContent())->toBe('pong');
    } finally {
        rename($webRoutes . '.bak', $webRoutes);
    }
});

test('csrf token global is fresh per request after reset', function () {
    Kernel::handle(Request::create('/ping'));

    /** @var \Twig\Environment $env */
    $env = Kernel::app()->get('view.env');

    $tokenR1 = $env->createTemplate('{{ _csrf_token }}')->render();
    $tokenR1Again = $env->createTemplate('{{ _csrf_token }}')->render();

    // Stable within one request…
    expect($tokenR1)->not->toBe('')
        ->and($tokenR1Again)->toBe($tokenR1);

    Kernel::resetForRequest();
    Kernel::handle(Request::create('/ping'));

    // …but reflects the NEW session after reset (not the old one).
    $tokenR2 = $env->createTemplate('{{ _csrf_token }}')->render();
    expect($tokenR2)->not->toBe('')
        ->and($tokenR2)->not->toBe($tokenR1);

    // And the fresh token is genuinely tied to the new session: the CSRF
    // manager validates it.
    $manager = Kernel::app()->get('csrf');
    expect($manager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('web', $tokenR2)))->toBeTrue();
});
