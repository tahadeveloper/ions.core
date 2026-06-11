<?php

declare(strict_types=1);

namespace Ions\Http;

use Illuminate\Cache\Repository;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Full-page response cache (Phase 12.5).
 *
 * Stores the status / safe headers / body of cacheable GET responses in the
 * container's cache store ('cache.store') and rehydrates them on a later hit.
 * Deliberately conservative: it only ever caches anonymous, side-effect-free
 * GET 200s, and it strips per-user state (Set-Cookie, hop-by-hop headers)
 * before storing so a shared cache can never leak one user's data to another.
 *
 * The CacheResponseMiddleware ('cache.response' alias) drives it; this class
 * is the pure cache logic so it can be unit-tested without the HTTP pipeline.
 *
 * Config (config('cache.response')):
 *   - enabled (bool, default true)   — master switch (middleware also bypasses
 *     on APP_DEBUG and non-GET regardless of this).
 *   - ttl (int, default 300)         — store TTL in seconds.
 *   - max_ttl (int, default 86400)   — hard ceiling applied to any requested
 *     TTL (a Cache-Control max-age can never push it past this).
 *   - prefix (string)                — cache key prefix; also the value used by
 *     `cache:clear-responses` to identify response-cache entries.
 *   - tag (string)                   — tag applied when the store supports tags
 *     (redis/memcached), enabling a targeted purge.
 */
final class ResponseCache
{
    /**
     * Headers that must never be copied from a cached response into the live
     * one (or stored): they describe the original hop / connection, not the
     * cacheable payload. Set-Cookie is excluded because a cached response is
     * served to many clients — a stored cookie would leak a session.
     *
     * @var list<string>
     */
    private const STRIPPED_HEADERS = [
        'set-cookie',
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    public function __construct(private readonly Repository $store)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('cache.response.enabled', true);
    }

    public function ttl(): int
    {
        return max(1, (int) config('cache.response.ttl', 300));
    }

    public function maxTtl(): int
    {
        return max(1, (int) config('cache.response.max_ttl', 86400));
    }

    public function prefix(): string
    {
        return (string) config('cache.response.prefix', 'response_cache:');
    }

    public function tag(): string
    {
        return (string) config('cache.response.tag', 'ions_response_cache');
    }

    /**
     * Whether this request/response pair is safe to cache.
     *
     * Conservative by design — ALL of these must hold:
     *   - GET request (no side-effect verbs);
     *   - 200 OK only;
     *   - the request carries no session and no resolved auth user
     *     (auth_user / auth_user_id attributes from AuthMiddleware);
     *   - the response is not marked private/no-store and carries no Set-Cookie
     *     (a Set-Cookie means per-user state — never shareable);
     *   - the response is fresh enough to be worth storing (no max-age:0).
     */
    public function shouldCache(Request $request, Response $response): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($this->requestIsStateful($request)) {
            return false;
        }

        $headers = $response->headers;

        if ($this->explicitlyUncacheable($response)) {
            return false;
        }

        // Any Set-Cookie means the response is establishing per-client state.
        if ($headers->getCookies() !== [] || $headers->has('Set-Cookie')) {
            return false;
        }

        // A controller that explicitly opted out via max-age:0 is respected.
        $maxAge = $headers->getCacheControlDirective('max-age');
        if ($maxAge !== null && (int) $maxAge <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Whether the response was *explicitly* marked uncacheable by the
     * controller (private / no-store / no-cache).
     *
     * Symfony computes a conservative `no-cache, private` Cache-Control for any
     * response that set NONE of its own caching directives, so
     * hasCacheControlDirective() can't tell a deliberate opt-out from the
     * default — and an explicit `no-cache` produces that exact same string, so
     * it is indistinguishable from "unmarked" and therefore NOT treated as an
     * opt-out (a full-page cache backed by ETag/304 revalidation is anyway
     * compatible with no-cache's "revalidate before reuse" intent).
     *
     * What we DO honour as deliberate opt-outs, read from the raw stored
     * Cache-Control header:
     *   - no-store      — only ever set explicitly;
     *   - private       — when present in any combination OTHER than the bare
     *     conservative defaults Symfony emits for an unmarked response
     *     ("no-cache, private" / "private, must-revalidate").
     */
    private function explicitlyUncacheable(Response $response): bool
    {
        $raw = $response->headers->get('Cache-Control');
        if ($raw === null || $raw === '') {
            return false;
        }

        // no-store is only ever set explicitly — always an opt-out.
        if (str_contains($raw, 'no-store')) {
            return true;
        }

        // The conservative defaults Symfony emits for an unmarked response.
        if ($raw === 'no-cache, private' || $raw === 'private, must-revalidate') {
            return false;
        }

        return str_contains($raw, 'private');
    }

    /**
     * Whether the request is tied to a specific user — a session carrying data
     * or a resolved auth user. Such responses are NEVER cached (they may embed
     * per-user data / CSRF tokens / flash messages).
     *
     * IMPORTANT: the web stack's StartSessionMiddleware starts a session on
     * EVERY request, so "session started" alone is far too broad — it would
     * disable the cache for all web traffic. We treat a request as stateful
     * only when the session actually holds DATA (all() is non-empty); an empty,
     * just-started session is effectively anonymous (Symfony emits no session
     * cookie for it, and the response carries no per-user state). The
     * response-side Set-Cookie check in shouldCache() is the companion guard
     * for the moment a handler writes to the session.
     */
    public function requestIsStateful(Request $request): bool
    {
        if ($request->attributes->get('auth_user') !== null
            || $request->attributes->get('auth_user_id') !== null) {
            return true;
        }

        try {
            if ($request->hasSession()) {
                $session = $request->getSession();
                if ($session->isStarted() && $session->all() !== []) {
                    return true;
                }
            }
        } catch (Throwable) {
            // No session attached — treat as stateless.
        }

        return false;
    }

    /**
     * Build the cache key: method + scheme + host + path + sorted query, plus
     * the request values of any Vary header the (fresh) response declared, so
     * two representations of the same URL (e.g. by Accept) never collide.
     *
     * @param list<string> $vary Header names from the response's Vary list.
     */
    public function key(Request $request, array $vary = []): string
    {
        $query = $request->query->all();
        ksort($query);

        $parts = [
            $request->getMethod(),
            $request->getScheme(),
            $request->getHttpHost(),
            $request->getPathInfo(),
            http_build_query($query),
        ];

        foreach ($vary as $header) {
            $name = strtolower(trim($header));
            if ($name === '' || $name === '*') {
                continue;
            }
            $parts[] = $name . '=' . (string) $request->headers->get($name, '');
        }

        return $this->prefix() . sha1(implode('|', $parts));
    }

    /**
     * The repository to read/write entries through: the tag-scoped repository
     * when the store supports tags (so a later tag-flush finds them), or the
     * plain store otherwise. get/put/flush all go through this so reads and
     * writes always land in the same namespace.
     */
    private function repository(): Repository
    {
        try {
            if ($this->store->supportsTags()) {
                /** @var Repository $tagged */
                $tagged = $this->store->tags([$this->tag()]);

                return $tagged;
            }
        } catch (Throwable) {
            // Fall through to the plain store.
        }

        return $this->store;
    }

    /**
     * Record, at the base (Vary-less) key, the list of headers a representation
     * varies on, so a later lookup can fold the right request values into its
     * key before reading the variant. Stored under a distinct ':vary' suffix so
     * it never collides with a response body entry.
     *
     * @param list<string> $vary
     */
    public function rememberVary(string $baseKey, array $vary, int $ttl): void
    {
        $ttl = max(1, min($ttl, $this->maxTtl()));
        $this->repository()->put($baseKey . ':vary', $vary, $ttl);
    }

    /**
     * The Vary header list previously recorded for $baseKey, or [] when none.
     *
     * @return list<string>
     */
    public function rememberedVary(string $baseKey): array
    {
        try {
            $vary = $this->repository()->get($baseKey . ':vary');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($vary)) {
            return [];
        }

        return array_values(array_filter($vary, 'is_string'));
    }

    /**
     * Rehydrate a stored response, or null on a miss / unreadable entry.
     */
    public function get(string $key): ?Response
    {
        try {
            $payload = $this->repository()->get($key);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($payload)
            || !isset($payload['status'], $payload['headers'], $payload['content'])
            || !is_array($payload['headers'])
            || !is_string($payload['content'])) {
            return null;
        }

        /** @var array<string, list<string>> $headers */
        $headers = $payload['headers'];

        $response = new Response($payload['content'], (int) $payload['status']);
        $response->headers->replace($headers);

        return $response;
    }

    /**
     * Store a response (status + safe headers + body) under $key with TTL.
     *
     * Set-Cookie and hop-by-hop headers are stripped before storing. The TTL
     * is clamped to max_ttl. The entry is written through the tag-scoped
     * repository when the store supports tags, so `cache:clear-responses` can
     * later purge exactly these entries.
     */
    public function put(string $key, Response $response, int $ttl): void
    {
        $ttl = max(1, min($ttl, $this->maxTtl()));

        $payload = [
            'status' => $response->getStatusCode(),
            'headers' => $this->safeHeaders($response),
            'content' => (string) $response->getContent(),
        ];

        $this->repository()->put($key, $payload, $ttl);
    }

    /**
     * Flush response-cache entries. Returns true when a targeted tag-flush was
     * used (the store supports tags — only the response-cache tag is purged,
     * other entries survive); false when the store has no tag support and the
     * documented fallback of flushing the whole store was used (file/database
     * stores expose no key-prefix scan, so a full flush is the only option).
     */
    public function flush(): bool
    {
        try {
            if ($this->store->supportsTags()) {
                $this->store->tags([$this->tag()])->flush();

                return true;
            }
        } catch (Throwable) {
            // Fall through to a full flush.
        }

        $this->store->clear();

        return false;
    }

    /**
     * The response headers safe to store: lower-cased Set-Cookie + hop-by-hop
     * entries removed.
     *
     * @return array<string, list<string>>
     */
    private function safeHeaders(Response $response): array
    {
        /** @var array<string, list<string>> $all */
        $all = $response->headers->all();

        foreach (self::STRIPPED_HEADERS as $strip) {
            unset($all[$strip]);
        }

        return $all;
    }
}
