<?php

declare(strict_types=1);

namespace Ions\Auth\Http;

use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate that requires the authenticated user's email to be verified.
 *
 * Register it under the 'verified' alias and attach it per route:
 *
 *   // config/app.php
 *   'middleware_aliases' => ['verified' => Ions\Auth\Http\EnsureEmailVerified::class],
 *
 *   Route::get('/dashboard', ...)->middleware(['verified']);
 *
 * Opt-in by design: the gate inspects the request's `auth_user` attribute (set
 * by {@see \Ions\Http\Middleware\AuthMiddleware}). It only blocks when that
 * user implements {@see VerifiesEmail} AND is unverified — a missing user, or a
 * user model that does not implement the contract, passes straight through.
 *
 * Content-negotiated rejection (mirrors {@see \Ions\Http\ExceptionHandler}):
 *   - API/JSON requests ({@see Request::wantsJson()} or a path under /api) get
 *     a 403 via abort(403) — rendered as JSON by the kernel.
 *   - Other (web) requests are redirected to
 *     config('app.auth.email_verification_redirect', '/email/verify').
 */
final class EnsureEmailVerified implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attributes->get('auth_user');

        // Opt-in: only VerifiesEmail users are gated; anything else passes.
        if (!$user instanceof VerifiesEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->wantsJson() || $request->segment(1) === 'api') {
            abort(403, 'Your email address is not verified.');
        }

        $to = (string) config('app.auth.email_verification_redirect', '/email/verify');

        return (new \Ions\Http\Redirector())->to($to);
    }
}
