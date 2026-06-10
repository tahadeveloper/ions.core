<?php

use Ions\Bundles\Route;
use IonsFixtureCache\Http\Api\ItemController;

Route::get('/api/cached', ItemController::class . '::show', [], 'api.cached');
