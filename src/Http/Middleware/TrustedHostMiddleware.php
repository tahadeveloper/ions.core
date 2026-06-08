<?php

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrustedHostMiddleware implements MiddlewareInterface
{
    public function __construct(private array $patterns)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->patterns !== []) {
            Request::setTrustedHosts($this->patterns);
        }

        return $next($request);
    }
}
