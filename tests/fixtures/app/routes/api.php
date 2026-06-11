<?php

use Ions\Auth\Http\AuthController;
use Ions\Bundles\Route;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

Route::get('/api/secret', function (Request $request) {
    // auth_user_id is set by AuthMiddleware before this runs
    return new Response('secret for ' . $request->attributes->get('auth_user_id'));
});

/*
|--------------------------------------------------------------------------
| Auth surface (login / refresh / logout / password reset)
|--------------------------------------------------------------------------
| These paths are listed in config('app.auth.public_paths') so AuthMiddleware
| lets them through unauthenticated. The framework ships Ions\Auth\Http\AuthController;
| here we reference its actions via closures so the api-namespace controller
| resolution does not prefix the FQCN. Login is rate-limited via the 'throttle' alias.
*/
Route::post('/api/auth/login', fn (Request $r) => (new AuthController())->login($r))
    ->middleware(['throttle']);
Route::post('/api/auth/refresh', fn (Request $r) => (new AuthController())->refresh($r));
Route::post('/api/auth/logout', fn (Request $r) => (new AuthController())->logout($r));
Route::post('/api/auth/password/forgot', fn (Request $r) => (new AuthController())->forgotPassword($r));
Route::post('/api/auth/password/reset', fn (Request $r) => (new AuthController())->resetPassword($r));

/*
|--------------------------------------------------------------------------
| Protected test route that shares a string prefix with the public login path
|--------------------------------------------------------------------------
| Used by the segment-boundary regression test to confirm that the public
| entry '/api/auth/login' does NOT grant access to '/api/auth/login-history'.
*/
Route::get('/api/auth/login-history', function (Request $request) {
    return new Response('history for ' . $request->attributes->get('auth_user_id'));
});

// Protected route with a path placeholder — exercises OpenAPI path params.
Route::get('/api/items/{id}', function (Request $request) {
    return new Response('item ' . $request->attributes->get('id'));
});

/*
|--------------------------------------------------------------------------
| Gate fixtures (Phase 10.4) — protected api routes
|--------------------------------------------------------------------------
| Behind AuthMiddleware (not in public_paths): actingAs() issues a real JWT
| and FixtureUserProvider resolves 'auth_user', which the gate reads. The
| closures instantiate the controller directly (AuthController precedent) so
| the api-namespace controller resolution does not prefix the FQCN.
*/
Route::get('/api/gate/secret', fn (Request $r) => (new \IonsFixture\Api\GateApiController())->secret($r));
Route::get('/api/gate/post-update', fn (Request $r) => (new \IonsFixture\Api\GateApiController())->updatePost($r));

/*
|--------------------------------------------------------------------------
| Public JSON echo endpoint (Ions\Testing kit feature tests)
|--------------------------------------------------------------------------
| Listed in config('app.auth.public_paths') so it bypasses AuthMiddleware.
| Echoes the decoded JSON body plus selected request headers so the test
| kit can verify json() round-trips and default-header merging.
*/
Route::post('/api/echo', function (Request $request) {
    return new \Symfony\Component\HttpFoundation\JsonResponse([
        'json' => json_decode((string) $request->getContent(), true),
        'content_type' => $request->headers->get('Content-Type'),
        // Server bag directly — proves headers were passed INTO Request::create
        // as server keys, not patched onto the header bag afterwards.
        'server_content_type' => $request->server->get('CONTENT_TYPE'),
        'accept' => $request->headers->get('Accept'),
        'x_custom' => $request->headers->get('X-Custom'),
    ]);
});
