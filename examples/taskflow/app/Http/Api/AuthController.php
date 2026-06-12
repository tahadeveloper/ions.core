<?php

declare(strict_types=1);

namespace App\Http\Api;

use Ions\Auth\Http\AuthController as FrameworkAuthController;

/**
 * JSON auth API. Subclasses the framework's AuthController, which issues a JWT
 * access + refresh pair on valid credentials (via the container-bound 'jwt' +
 * the configured user_provider — Taskflow's App\Auth\UserProvider). The route
 * /api/auth/login is allow-listed in config('app.auth.public_paths'), so it
 * bypasses AuthMiddleware; the issued token then authenticates every other
 * /api/* route (AuthMiddleware resolves the App\Models\User onto the request).
 *
 * Endpoints inherited: login(), refresh(), logout(). The example wires login in
 * routes/api.php; the others are available for hosts that need them.
 */
final class AuthController extends FrameworkAuthController
{
}
