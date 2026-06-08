<?php

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    private array $origins;
    private array $methods;
    private array $headers;
    private int $maxAge;

    public function __construct(private array $config = [])
    {
        $this->origins = $config['origins'] ?? ['*'];
        $this->methods = $config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $this->headers = $config['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'];
        $this->maxAge  = $config['max_age'] ?? 3600;
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            $this->applyCorsHeaders($request, $response);
            $response->headers->set('Access-Control-Max-Age', (string) $this->maxAge);
            return $response;
        }

        $response = $next($request);
        $this->applyCorsHeaders($request, $response);
        return $response;
    }

    private function applyCorsHeaders(Request $request, Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->resolveOrigin($request));
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->methods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->headers));
    }

    private function resolveOrigin(Request $request): string
    {
        if ($this->origins === ['*']) {
            return '*';
        }

        $origin = $request->headers->get('Origin', '');
        if ($origin !== '' && in_array($origin, $this->origins, true)) {
            return $origin;
        }

        return '';
    }
}
