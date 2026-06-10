<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS middleware — deny by default (4.1, D8-1).
 *
 * With no configured `origins` (the default), NO CORS headers are emitted at
 * all: cross-origin browser requests are denied and preflights get a plain
 * 204 without Access-Control-* headers. Hosts that serve cross-origin
 * traffic must set config('app.cors.origins') explicitly (a list of exact
 * origins, or ['*'] for a public wildcard).
 *
 * Access-Control-Allow-Credentials is emitted only when
 * config('app.cors.credentials') is explicitly true AND a specific (non-'*')
 * origin list is configured — the Fetch spec forbids combining credentials
 * with the wildcard origin, so that combination silently drops the
 * credentials header.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $origins;
    /** @var string[] */
    private array $methods;
    /** @var string[] */
    private array $headers;
    private int $maxAge;
    private bool $credentials;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config = [])
    {
        $this->origins = (array) ($config['origins'] ?? []);
        $this->methods = (array) ($config['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $this->headers = (array) ($config['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']);
        $this->maxAge  = (int) ($config['max_age'] ?? 3600);
        $this->credentials = ($config['credentials'] ?? false) === true;
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            if ($this->applyCorsHeaders($request, $response)) {
                $response->headers->set('Access-Control-Max-Age', (string) $this->maxAge);
            }
            return $response;
        }

        $response = $next($request);
        $this->applyCorsHeaders($request, $response);
        return $response;
    }

    /**
     * Apply the CORS headers when (and only when) an origin is allowed.
     *
     * @return bool true when headers were applied.
     */
    private function applyCorsHeaders(Request $request, Response $response): bool
    {
        $origin = $this->resolveOrigin($request);
        if ($origin === '') {
            return false;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->methods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->headers));

        // Spec: credentials cannot be combined with the wildcard origin.
        if ($this->credentials && $origin !== '*') {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return true;
    }

    private function resolveOrigin(Request $request): string
    {
        if ($this->origins === []) {
            return ''; // deny-by-default: no CORS headers at all
        }

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
