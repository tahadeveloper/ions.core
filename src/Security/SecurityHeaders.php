<?php

declare(strict_types=1);

namespace Ions\Security;

use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /**
     * Apply security headers to the given response.
     *
     * The four non-CSP hardening headers (X-Content-Type-Options, X-Frame-Options,
     * Referrer-Policy, X-XSS-Protection) are set unconditionally by design: security
     * defaults are enforced, not opt-out, so callers cannot inadvertently omit them.
     *
     * Content-Security-Policy is intentionally exempt from this rule — it is only
     * applied if the caller has not already set one, allowing controllers/middleware
     * to supply a stricter route-specific policy without being overridden here.
     */
    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '0');
        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', config('app.security.csp', "default-src 'self'"));
        }
        return $response;
    }
}
