<?php

declare(strict_types=1);

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
            return $this->tooManyRequests($this->retryAfter($key));
        }

        // On first hit, add() sets the key with the TTL (so the window starts now).
        // On subsequent hits, increment() updates the counter without resetting the TTL.
        if ($count === 0) {
            $this->cache->add($key, 1, $this->decaySeconds);
            // Companion marker carrying the window's absolute end time, with the
            // same TTL as the counter. Read back on a 429 so Retry-After reports
            // the REMAINING window rather than the static decay — portable across
            // cache stores that do not expose a TTL read.
            $this->cache->add($key . ':reset', time() + $this->decaySeconds, $this->decaySeconds);
        } else {
            $this->cache->increment($key);
        }

        return $next($request);
    }

    private function buildKey(Request $request): string
    {
        return $this->prefix . sha1($request->getClientIp() . '|' . $request->getPathInfo());
    }

    /**
     * Seconds left in the current window, from the companion :reset marker.
     *
     * Falls back to the full decay when the marker is missing (e.g. evicted),
     * and is clamped to at least 1 so Retry-After is always actionable.
     */
    private function retryAfter(string $key): int
    {
        $reset = (int) $this->cache->get($key . ':reset', 0);
        if ($reset <= 0) {
            return $this->decaySeconds;
        }

        return max(1, $reset - time());
    }

    private function tooManyRequests(int $retryAfter): Response
    {
        $response = Json::error('Too many requests', 429, ['retry_after' => $retryAfter]);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
