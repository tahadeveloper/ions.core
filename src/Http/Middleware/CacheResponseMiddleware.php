<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Http\ResponseCache;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in full-page response cache middleware (Phase 12.5).
 *
 * Attach per route / group via the 'cache.response' alias
 * (config('app.middleware_aliases')) — it is NEVER in a default stack, so a
 * route is only cached when its author explicitly asks for it.
 *
 * Flow:
 *   1. Bypass entirely on APP_DEBUG, on non-GET, or when the cache is disabled
 *      (config('cache.response.enabled') === false) → call $next unchanged.
 *   2. Compute the cache key. On a HIT, serve the stored response with an
 *      `X-Ions-Cache: HIT` header — and honour conditional requests: if the
 *      cached response carries an ETag/Last-Modified that the request's
 *      If-None-Match/If-Modified-Since satisfies, return a 304.
 *   3. On a MISS, run $next. If ResponseCache::shouldCache() says yes, attach
 *      an ETag (hashed from the body when the response has none) so future
 *      conditional requests work, store it, and stamp `X-Ions-Cache: MISS`.
 *      A fresh-but-conditional request (matching If-None-Match) collapses to a
 *      304 even on the first hit.
 *
 * Safety: every cache interaction is wrapped so a cache failure can only ever
 * degrade to serving the live response — it NEVER breaks the request. Stateful
 * requests (started session, resolved auth user) and responses carrying
 * Set-Cookie / private / no-store are never cached (see ResponseCache).
 */
final class CacheResponseMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseCache $cache)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Debug builds always serve live (the toolbar / fresh state matter more
        // than the cache while developing); non-GET and the disabled switch
        // bypass too. None of these touch the store.
        if (env('APP_DEBUG', false) || $request->getMethod() !== 'GET' || !$this->cache->enabled()) {
            return $next($request);
        }

        try {
            $baseKey = $this->cache->key($request);
            // A prior MISS may have recorded which headers the representation
            // varies on; fold those request values into the lookup key so the
            // right variant is served (and variants never collide).
            $vary = $this->cache->rememberedVary($baseKey);
            $lookupKey = $vary === [] ? $baseKey : $this->cache->key($request, $vary);
            $cached = $this->cache->get($lookupKey);
        } catch (Throwable) {
            return $next($request);
        }

        if ($cached !== null) {
            $cached->headers->set('X-Ions-Cache', 'HIT');

            // Symfony's isNotModified() mutates the response in place to a 304
            // with an empty body when the request's If-None-Match /
            // If-Modified-Since satisfies the cached validators.
            $cached->isNotModified($request);

            return $cached;
        }

        $response = $next($request);

        try {
            if ($this->cache->shouldCache($request, $response)) {
                $this->ensureValidators($response);

                $ttl = $this->resolveTtl($response);
                $responseVary = array_values($response->getVary());

                if ($responseVary === []) {
                    $this->cache->put($baseKey, $response, $ttl);
                } else {
                    // Record the Vary dimension at the base key, then store the
                    // variant under the vary-aware key so later requests with
                    // the same varied-header values hit it.
                    $this->cache->rememberVary($baseKey, $responseVary, $ttl);
                    $this->cache->put($this->cache->key($request, $responseVary), $response, $ttl);
                }

                $response->headers->set('X-Ions-Cache', 'MISS');
            }
        } catch (Throwable) {
            // Never let a cache failure break the live response.
            return $response;
        }

        // Conditional request that matches the just-generated validators → 304
        // (saves the body transfer even on the first, uncached hit). Mutates
        // the response in place when satisfied.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Give the response an ETag (hashed from its body) when it has no validator,
     * so subsequent conditional requests can be answered with a 304.
     */
    private function ensureValidators(Response $response): void
    {
        if ($response->getEtag() !== null || $response->headers->has('Last-Modified')) {
            return;
        }

        $response->setEtag('"' . sha1((string) $response->getContent()) . '"');
    }

    /**
     * The TTL to store under: the response's own Cache-Control max-age when it
     * set one (and it is positive), otherwise the configured default. The
     * ResponseCache clamps the final value to max_ttl.
     */
    private function resolveTtl(Response $response): int
    {
        $maxAge = $response->headers->getCacheControlDirective('max-age');
        if ($maxAge !== null && (int) $maxAge > 0) {
            return (int) $maxAge;
        }

        return $this->cache->ttl();
    }
}
