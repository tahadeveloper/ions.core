<?php

/**
 * Cache-friendly routes: every controller is a class-string (no closures),
 * so this fixture can be compiled by route:cache.
 */

use Ions\Bundles\Route;
use IonsFixtureCache\Http\PageController;

Route::get('/cached', PageController::class . '::show', [], 'cached.show');
Route::get('/cached/{id}', PageController::class . '::item', [], 'cached.item');

// POST route protected by CSRF middleware — used by the 419-through-cache test.
Route::post('/cached/post', PageController::class . '::post', [], 'cached.post');

// --- 10.7 fluent routes (cacheable subset: no closures anywhere) ---
// Distinct '/fluent' prefix: '/cached/{id}' above is definition-order earlier
// and would shadow any one-segment '/cached/*' sibling.

// Fluent name(): the re-keyed name must be the collection key in the dump.
Route::get('/fluent/named', PageController::class . '::show')->name('fluent.named');

// Fluent where(): the requirement must be enforced by the compiled matcher.
Route::get('/fluent/where/{num}', PageController::class . '::show')->where('num', '\d+');

// Shortcut routes: framework controller strings + defaults — both compile.
Route::redirect('/fluent/old', '/cached', 301);
Route::view('/fluent/view', 'hello', ['name' => 'world']);
