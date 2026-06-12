<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyController;
use App\Http\Controllers\HomeController;
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
