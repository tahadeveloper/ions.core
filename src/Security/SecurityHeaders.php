<?php

namespace Ions\Security;

use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
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
