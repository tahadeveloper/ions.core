<?php

declare(strict_types=1);

use Ions\Http\Json;
use Ions\Bundles\Route;

// Sample endpoint — listed in config('app.auth.public_paths') so it bypasses
// AuthMiddleware. The Taskflow JSON API (auth, projects, tasks) is added in
// later sub-phases (13.3+).
Route::get('/api/ping', static fn () => Json::ok(['message' => 'pong']));
