<?php

declare(strict_types=1);

use App\Http\Api\AuthController;
use App\Http\Api\ProjectApiController;
use App\Http\Api\TaskApiController;
use Ions\Bundles\Route;
use Ions\Http\Json;

// Sample endpoint — listed in config('app.auth.public_paths') so it bypasses
// AuthMiddleware.
Route::get('/api/ping', static fn () => Json::ok(['message' => 'pong']));

// JSON login: returns a JWT access + refresh pair (public — see public_paths).
Route::post('/api/auth/login', AuthController::class . '::login');

// --- Projects API (13.4) ----------------------------------------------------
// Protected: AuthMiddleware verifies the Bearer token and resolves the
// App\Models\User onto the request; the ProjectApiController then gates each
// record through ProjectPolicy (a non-member gets a 403) and returns 7.7
// Resource / ResourceCollection envelopes (data + meta/links). {project}/{task}
// are route-model-bound (10.2). Exercised by actingAs() in the test suite.
Route::get('/api/projects', ProjectApiController::class . '::index');
Route::post('/api/projects', ProjectApiController::class . '::store');
Route::get('/api/projects/{project}', ProjectApiController::class . '::show');

// --- Tasks API, nested under a project --------------------------------------
Route::get('/api/projects/{project}/tasks', TaskApiController::class . '::index');
Route::post('/api/projects/{project}/tasks', TaskApiController::class . '::store');
Route::get('/api/projects/{project}/tasks/{task}', TaskApiController::class . '::show');
