<?php

use Ions\Foundation\Kernel;
use Ions\Security\Jwt;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

test('api route returns 401 without a valid token (AuthMiddleware enforces in the pipeline)', function () {
    $response = Kernel::handle(Request::create('/api/secret'));
    expect($response->getStatusCode())->toBe(401);
});

test('api route returns 200 with a valid Bearer token and the closure sees the auth_user_id', function () {
    // 'user-99' is a known id in the FixtureUserProvider — resolves successfully.
    $jwt = new Jwt((string) env('APP_KEY'), (string) env('APP_NAME'), (string) env('APP_NAME'), 3600);
    $token = $jwt->issue('user-99');

    $request = Request::create('/api/secret');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('user-99')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff'); // SecurityHeaders ran too
});

test('api route returns 401 when the token subject is unknown (D5-B: user no longer exists)', function () {
    // 'ghost-user' is NOT in the FixtureUserProvider → retrieveById returns null → 401.
    $jwt = new Jwt((string) env('APP_KEY'), (string) env('APP_NAME'), (string) env('APP_NAME'), 3600);
    $token = $jwt->issue('ghost-user');

    $request = Request::create('/api/secret');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(401);
});
