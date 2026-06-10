<?php

declare(strict_types=1);

use Ions\Http\Json;
use Ions\Bundles\Route;

// Sample endpoint — listed in config('app.auth.public_paths') so it bypasses
// AuthMiddleware. Remove both together.
Route::get('/api/ping', static fn () => Json::ok(['message' => 'pong']));

/*
|--------------------------------------------------------------------------
| JWT auth surface (optional)
|--------------------------------------------------------------------------
| The framework ships Ions\Auth\Http\AuthController (login / refresh /
| logout / password reset). It needs a configured user provider and its
| users table (config/auth.php) plus APP_KEY set. Uncomment to enable —
| login and refresh are already in app.auth.public_paths.
|
| use Ions\Auth\Http\AuthController;
| use Ions\Support\Request;
|
| Route::post('/api/auth/login', static fn (Request $r) => (new AuthController())->login($r))
|     ->middleware(['throttle']);
| Route::post('/api/auth/refresh', static fn (Request $r) => (new AuthController())->refresh($r));
*/
