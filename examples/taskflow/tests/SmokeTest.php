<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow smoke test
|--------------------------------------------------------------------------
| Boots the example host app in-process (Ions\Testing\TestCase, basePath =
| the example root) and exercises the scaffold end-to-end: config loads, the
| Twig welcome page renders, the built-in /up health probe answers and the
| sample JSON API endpoint responds. This is the 13.1 boot gate.
*/

test('the example boots: config loads and app.name resolves', function () {
    expect(config('app.name'))->toBe('Taskflow')
        // 4.1 secure defaults are embraced: CORS deny-by-default…
        ->and(config('app.cors.origins'))->toBe([])
        // …and the example defaults to SQLite.
        ->and(config('database.default'))->toBe('sqlite')
        // The sample API path is pre-allowlisted for AuthMiddleware.
        ->and(config('app.auth.public_paths'))->toContain('/api/ping');
});

test('GET / renders the Taskflow Twig welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Taskflow', escape: false)
        ->assertSee('Ions PHP framework', escape: false)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('GET /up returns 200 from the built-in health probe', function () {
    $this->get('/up')->assertOk();
});

test('GET /api/ping returns 200 JSON', function () {
    $this->get('/api/ping')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['status' => 'success', 'data' => ['message' => 'pong']]);
});
