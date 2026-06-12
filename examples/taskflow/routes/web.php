<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Ions\Bundles\Route;

Route::get('/', HomeController::class . '::index');

// --- Registration -----------------------------------------------------------
Route::get('/register', RegisterController::class . '::create');
Route::post('/register', RegisterController::class . '::store');

// --- Email verification -----------------------------------------------------
// EmailVerification::verificationUrl() builds a signed link to this exact route
// name; the 'signed' middleware rejects a tampered/expired link up front.
Route::get('/email/verify', VerifyController::class . '::verify', [], 'verification.verify')
    ->middleware(['signed']);

// --- Login / logout ---------------------------------------------------------
Route::get('/login', LoginController::class . '::create');
Route::post('/login', LoginController::class . '::store');
Route::post('/logout', LoginController::class . '::destroy');

// --- Two-factor login challenge ---------------------------------------------
Route::get('/login/2fa', TwoFactorController::class . '::challenge');
Route::post('/login/2fa', TwoFactorController::class . '::verify');

// --- Two-factor enrolment (requires a logged-in, verified web session) ------
Route::get('/2fa', TwoFactorController::class . '::enable')->middleware(['web.auth', 'verified']);
Route::post('/2fa', TwoFactorController::class . '::confirm')->middleware(['web.auth', 'verified']);
Route::post('/2fa/disable', TwoFactorController::class . '::disable')->middleware(['web.auth']);

// --- Dashboard: a verified-only landing page that proves the gate -----------
Route::get('/dashboard', HomeController::class . '::dashboard')->middleware(['web.auth', 'verified']);

// --- Projects CRUD (13.4) ----------------------------------------------------
// Every route gates on a verified, logged-in web session. {project}/{task} are
// route-model-bound (10.2): the typed Project/Task action params resolve by
// route key (404 on miss) before the action runs. Static segments (/create,
// /tasks) are declared before the {placeholder} routes so they match first.
$crud = ['web.auth', 'verified'];

Route::get('/projects', ProjectController::class . '::index')->middleware($crud);
Route::get('/projects/create', ProjectController::class . '::create')->middleware($crud);
Route::post('/projects', ProjectController::class . '::store')->middleware($crud);
Route::get('/projects/{project}', ProjectController::class . '::show')->middleware($crud);
Route::get('/projects/{project}/edit', ProjectController::class . '::edit')->middleware($crud);
Route::post('/projects/{project}', ProjectController::class . '::update')->middleware($crud);
Route::post('/projects/{project}/delete', ProjectController::class . '::destroy')->middleware($crud);

// --- Tasks CRUD, nested under a project (13.4) ------------------------------
Route::get('/projects/{project}/tasks/create', TaskController::class . '::create')->middleware($crud);
Route::post('/projects/{project}/tasks', TaskController::class . '::store')->middleware($crud);
Route::get('/projects/{project}/tasks/{task}', TaskController::class . '::show')->middleware($crud);
Route::get('/projects/{project}/tasks/{task}/edit', TaskController::class . '::edit')->middleware($crud);
Route::post('/projects/{project}/tasks/{task}', TaskController::class . '::update')->middleware($crud);
Route::post('/projects/{project}/tasks/{task}/delete', TaskController::class . '::destroy')->middleware($crud);
// Assigning a task dispatches SendTaskAssignedNotification (13.5).
Route::post('/projects/{project}/tasks/{task}/assign', TaskController::class . '::assign')->middleware($crud);
Route::post('/projects/{project}/tasks/{task}/comment', TaskController::class . '::comment')->middleware($crud);

// --- In-app notifications (13.5) --------------------------------------------
// Lists the logged-in user's database-channel notifications.
Route::get('/notifications', NotificationController::class . '::index')->middleware($crud);
Route::post('/notifications/{id}/read', NotificationController::class . '::markRead')->middleware($crud);
