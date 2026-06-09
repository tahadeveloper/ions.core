<?php

declare(strict_types=1);

namespace Ions\Security;

/**
 * In-memory revocation store.
 *
 * Suitable for tests and single-request scenarios.
 * For cross-request revocation, bind a persistent (cache-backed) RevocationStore
 * implementation into the container as 'revocation_store'.
 */
final class ArrayRevocationStore implements RevocationStore
{
    /** @var array<string, int> jti → unix-timestamp expiry (0 = no expiry / permanent) */
    private array $revoked = [];

    public function revoke(string $jti, int $ttlSeconds): void
    {
        $this->revoked[$jti] = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
    }

    public function isRevoked(string $jti): bool
    {
        if (!array_key_exists($jti, $this->revoked)) {
            return false;
        }

        $expiry = $this->revoked[$jti];

        // 0 means permanent revocation (no ttl).
        if ($expiry === 0) {
            return true;
        }

        // If the entry itself has passed its own TTL we can clean up and treat
        // it as not revoked (the original JWT would be expired anyway).
        if (time() > $expiry) {
            unset($this->revoked[$jti]);
            return false;
        }

        return true;
    }
}
