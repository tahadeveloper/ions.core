<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $origins;
    /** @var string[] */
    private array $methods;
    /** @var string[] */
    private array $headers;
    private int $maxAge;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
        $this->origins = (array) ($config['origins'] ?? ['*']);
        $this->methods = (array) ($config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $this->headers = (array) ($config['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']);
        $this->maxAge  = (int) ($config['max_age'] ?? 3600);
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

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin !== '' && in_array($origin, $this->origins, true)) {
            return $origin;
        }

        return '';
    }
}
