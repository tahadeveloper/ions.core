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
