<?php

declare(strict_types=1);

use App\Http\Api\AuthController;
use App\Models\Project;
use Ions\Bundles\Route;
use Ions\Http\Json;
use Ions\Support\Request;

// Sample endpoint — listed in config('app.auth.public_paths') so it bypasses
// AuthMiddleware.
Route::get('/api/ping', static fn () => Json::ok(['message' => 'pong']));

// JSON login: returns a JWT access + refresh pair (public — see public_paths).
Route::post('/api/auth/login', AuthController::class . '::login');

// Protected: AuthMiddleware verifies the Bearer token and resolves the
// App\Models\User onto the request, then the Gate's ProjectPolicy::view gates
// access. A non-member gets a 403. Exercised by actingAs() in the test suite.
Route::get('/api/projects/{id}', static function (Request $request, string $id) {
    $project = Project::query()->find($id);
    if ($project === null) {
        return Json::error('Not found', 404);
    }

    /** @var \Ions\Auth\Gate $gate */
    $gate = app('gate');
    $gate->authorize('view', $project);

    return Json::ok([
        'id' => $project->getKey(),
        'name' => $project->name,
    ]);
});
