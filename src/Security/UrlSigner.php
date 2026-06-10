<?php

declare(strict_types=1);

namespace Ions\Security;

/**
 * HMAC-SHA256 URL signing for tamper-proof links (verify-email, unsubscribe,
 * temporary downloads).
 *
 * The signature covers the canonical form of the URL: path + '?' + the query
 * parameters sorted by key (excluding 'signature' itself), so verification is
 * independent of parameter order. Order independence applies to TOP-LEVEL
 * parameters only: canonicalization sorts the first level, so reordering keys
 * inside a nested array parameter (a[x]=1&a[y]=2 vs a[y]=2&a[x]=1) changes the
 * canonical string and the URL fails verification — fails closed, never open.
 * Scheme and host are intentionally excluded —
 * the same link verifies behind proxies or when the request URI is relative —
 * host integrity is already enforced by the kernel's host gate / TrustedHost.
 *
 * Keys derive from APP_KEY via HKDF-SHA256 with info "ions.urlsigner.v1",
 * distinct from the Encrypter and JWT derivations.
 */
final class UrlSigner
{
    private const HKDF_INFO = 'ions.urlsigner.v1';

    /** Query parameter carrying the HMAC. */
    public const SIGNATURE_PARAM = 'signature';

    /** Query parameter carrying the expiry epoch (optional). */
    public const EXPIRES_PARAM = 'expires';

    /**
     * @param string $key At least 32 raw bytes (use {@see fromAppKey()} to derive one).
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException(
                'UrlSigner key must be at least 32 raw bytes; use UrlSigner::fromAppKey() to derive one.'
            );
        }
    }

    /**
     * Derive a UrlSigner from the application key (env APP_KEY).
     *
     * @throws \RuntimeException when the key is shorter than 32 bytes — the
     *                           same minimum the JWT subsystem enforces.
     */
    public static function fromAppKey(#[\SensitiveParameter] string $appKey): self
    {
        if (strlen($appKey) < 32) {
            throw new \RuntimeException(
                'APP_KEY must be at least 32 bytes to sign URLs. Set a strong APP_KEY in your .env.'
            );
        }

        return new self(hash_hkdf('sha256', $appKey, 32, self::HKDF_INFO));
    }

    /**
     * Sign a URL (relative or absolute), optionally with an expiry.
     *
     * Appends an 'expires' epoch parameter when $expiresAt is given, then a
     * 'signature' parameter. Any pre-existing 'signature' param is discarded.
     * URL fragments (#...) are dropped from the result: fragments are never
     * sent to servers, so they cannot be covered by — or verified against —
     * the signature.
     */
    public function sign(string $url, \DateTimeInterface|int|null $expiresAt = null): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException("Cannot sign malformed URL '{$url}'.");
        }

        $params = self::queryParams($parts['query'] ?? '');
        unset($params[self::SIGNATURE_PARAM]);

        if ($expiresAt !== null) {
            $expires = $expiresAt instanceof \DateTimeInterface ? $expiresAt->getTimestamp() : $expiresAt;
            $params[self::EXPIRES_PARAM] = (string) $expires;
        }

        $path = $parts['path'] ?? '/';
        $params[self::SIGNATURE_PARAM] = $this->hmac($path, $params);

        $origin = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return $origin . $path . '?' . http_build_query($params);
    }

    /**
     * Verify a signed URL: recomputes the HMAC over the received parameters
     * (minus 'signature'), compares in constant time, then checks expiry.
     */
    public function verify(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $params = self::queryParams($parts['query'] ?? '');

        $signature = $params[self::SIGNATURE_PARAM] ?? null;
        if (!is_string($signature) || $signature === '') {
            return false;
        }
        unset($params[self::SIGNATURE_PARAM]);

        $expected = $this->hmac($parts['path'] ?? '/', $params);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $expires = $params[self::EXPIRES_PARAM] ?? null;
        if ($expires !== null) {
            if (!is_string($expires) || !ctype_digit($expires)) {
                return false;
            }
            if ((int) $expires < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical HMAC: path + '?' + query params sorted by key.
     *
     * @param array<int|string, mixed> $params
     */
    private function hmac(string $path, array $params): string
    {
        ksort($params);

        return hash_hmac('sha256', $path . '?' . http_build_query($params), $this->key);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function queryParams(string $query): array
    {
        parse_str($query, $params);

        return $params;
    }
}
