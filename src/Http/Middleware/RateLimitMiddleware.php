<?php

namespace Ions\Http\Middleware;

use Illuminate\Contracts\Cache\Repository;
use Ions\Http\Json;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache-backed rate-limiting middleware.
 *
 * Throttles requests by a composite key of the client IP and request path.
 * When the limit is exceeded it returns a 429 response with a Retry-After header.
 *
 * Attach to individual routes via Route::middleware([RateLimitMiddleware::class])
 * or via the 'throttle' alias configured in app.middleware_aliases.
 *
 * Config keys:
 *   app.ratelimit.max   — max attempts per window (default 60)
 *   app.ratelimit.decay — window length in seconds (default 60)
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly string $prefix = 'ratelimit:',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $key = $this->buildKey($request);
        $count = (int) $this->cache->get($key, 0);

        if ($count >= $this->maxAttempts) {
            return $this->tooManyRequests($this->decaySeconds);
        }

        // On first hit, add() sets the key with the TTL (so the window starts now).
        // On subsequent hits, increment() updates the counter without resetting the TTL.
        if ($count === 0) {
            $this->cache->add($key, 1, $this->decaySeconds);
        } else {
            $this->cache->increment($key);
        }

        return $next($request);
    }

    private function buildKey(Request $request): string
    {
        return $this->prefix . sha1($request->getClientIp() . '|' . $request->getPathInfo());
    }

    private function tooManyRequests(int $retryAfter): Response
    {
        $response = Json::error('Too many requests', 429, ['retry_after' => $retryAfter]);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
