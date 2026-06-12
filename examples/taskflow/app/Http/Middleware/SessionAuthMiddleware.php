<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Http\Redirector;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web session authentication.
 *
 * The framework's default 'web' middleware group carries no auth (only the
 * 'api' group runs AuthMiddleware on the Bearer token). The host owns its web
 * session login: LoginController calls session()->put('auth_user_id', $id) on
 * success, and this middleware — attached per route under the 'web.auth' alias
 * — reads that id, loads the User, and publishes it onto the request as the
 * 'auth_user' / 'auth_user_id' attributes. That is exactly what the Gate
 * (policies, $this->authorize()) and the 'verified' middleware read, so chaining
 * `->middleware(['web.auth', 'verified'])` gates a web route on a verified,
 * logged-in user.
 *
 * A request with no session user is redirected to /login (web auth gate).
 */
final class SessionAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $id = session('auth_user_id');

        if ($id === null) {
            return (new Redirector())->to('/login');
        }

        $user = User::query()->find($id);
        if ($user === null) {
            // Stale session (user deleted): drop it and send to login.
            session()->forget('auth_user_id');

            return (new Redirector())->to('/login');
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_user_id', $user->getKey());

        return $next($request);
    }
}
