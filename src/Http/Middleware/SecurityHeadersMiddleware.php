<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Security\SecurityHeaders;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return SecurityHeaders::apply($response, $request);
    }
}
