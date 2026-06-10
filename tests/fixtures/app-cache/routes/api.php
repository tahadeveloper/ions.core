<?php

use Ions\Bundles\Route;
use IonsFixtureCache\Http\Api\ItemController;

// Public route (listed in auth.public_paths): cached vs live comparisons.
Route::get('/api/cached', ItemController::class . '::show', [], 'api.cached');

// Protected route (NOT in public_paths): requires a valid Bearer token.
// Used by the 401-through-cache security test.
Route::get('/api/secret', ItemController::class . '::secret', [], 'api.secret');

// Rate-limited route: returns 429 when the limit is exceeded.
// Used by the 429-through-cache security test.
Route::get('/api/throttled', ItemController::class . '::throttled', [], 'api.throttled')
    ->middleware(['throttle']);
