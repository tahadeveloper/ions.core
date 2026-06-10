<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Security\UrlSigner;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests whose URL does not carry a valid (and unexpired) signature
 * produced by {@see UrlSigner} / signedRoute().
 *
 * Attach per route via the 'signed' alias configured in app.middleware_aliases:
 *
 *     Route::get('/verify-email', ..., 'verify.email')->middleware(['signed']);
 *
 * The UrlSigner is constructor-injected from the container (SecurityProvider
 * binds the class alias), so it is only resolved when a signed route is hit.
 */
final class ValidateSignatureMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UrlSigner $signer)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->signer->verify($request->getRequestUri())) {
            abort(403, 'Invalid signature.');
        }

        return $next($request);
    }
}
