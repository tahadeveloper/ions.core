<?php

/**
 * Cache-friendly routes: every controller is a class-string (no closures),
 * so this fixture can be compiled by route:cache.
 */

use Ions\Bundles\Route;
use IonsFixtureCache\Http\PageController;

Route::get('/cached', PageController::class . '::show', [], 'cached.show');
Route::get('/cached/{id}', PageController::class . '::item', [], 'cached.item');
