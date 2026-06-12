<?php

declare(strict_types=1);

namespace Ions\Security;

use Symfony\Component\HttpFoundation\Request;
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
     * Content-Security-Policy, Strict-Transport-Security and Permissions-Policy are
     * intentionally exempt from that rule — they are only applied if the caller has
     * not already set one, allowing controllers/middleware to supply a stricter
     * route-specific policy without being overridden here.
     *
     * Strict-Transport-Security is additionally only emitted when the handled
     * request is HTTPS ($request->isSecure()); an HSTS header on a plain-HTTP
     * response is ignored by browsers anyway. Pass the request when available
     * (SecurityHeadersMiddleware and Kernel::sendResponse() do).
     * Config: app.security.hsts — string to override the policy, false to disable.
     *
     * Permissions-Policy defaults to a restrictive `camera=(), geolocation=(),
     * microphone=()`. Config: app.security.permissions_policy — string to
     * override, false to disable.
     */
    public static function apply(Response $response, ?Request $request = null): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '0');

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', config('app.security.csp', self::defaultCsp()));
        }

        if ($request !== null && $request->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $hsts = config('app.security.hsts', 'max-age=31536000; includeSubDomains');
            if (is_string($hsts) && $hsts !== '') {
                $response->headers->set('Strict-Transport-Security', $hsts);
            }
        }

        if (!$response->headers->has('Permissions-Policy')) {
            $policy = config('app.security.permissions_policy', 'camera=(), geolocation=(), microphone=()');
            if (is_string($policy) && $policy !== '') {
                $response->headers->set('Permissions-Policy', $policy);
            }
        }

        return $response;
    }

    /**
     * The default Content-Security-Policy.
     *
     * The framework ships self-contained, INLINE-styled pages — the welcome
     * page, the production error pages, the debug page and the debug toolbar —
     * whose CSS is embedded in a `<style>` block (an error page must render even
     * when the asset pipeline is broken, so it cannot link an external
     * stylesheet). A bare `default-src 'self'` blocks all inline styles, so
     * every first-party page would render UNSTYLED in the browser. The default
     * therefore permits inline STYLES (`style-src 'unsafe-inline'`) — a low-risk
     * relaxation (CSS injection is far weaker than script injection) that real
     * apps commonly use. Inline SCRIPT stays blocked in production. When
     * `APP_DEBUG` is on, inline script is also permitted so the interactive
     * debug page + toolbar (their inline `<script>`) work locally.
     *
     * Override entirely with `config('app.security.csp', '…')` — set a stricter
     * policy (e.g. nonce/hash-based) and externalise your own styles if needed.
     */
    private static function defaultCsp(): string
    {
        $csp = "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:";

        if ((bool) env('APP_DEBUG', false)) {
            $csp .= "; script-src 'self' 'unsafe-inline'";
        }

        return $csp;
    }
}
