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

    /** @var array<string, int> fid → unix-timestamp expiry (0 = no expiry / permanent) */
    private array $revokedFamilies = [];

    public function revoke(string $jti, int $ttlSeconds): void
    {
        $this->revoked[$jti] = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
    }

    public function isRevoked(string $jti): bool
    {
        return $this->isExpired($this->revoked, $jti);
    }

    public function revokeFamily(string $fid, int $ttlSeconds): void
    {
        $this->revokedFamilies[$fid] = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
    }

    public function isFamilyRevoked(string $fid): bool
    {
        return $this->isExpired($this->revokedFamilies, $fid);
    }

    /**
     * Shared lookup over a key→expiry map: 0 = permanent; a passed expiry is
     * cleaned up and treated as not revoked (the JWT itself would be expired).
     *
     * @param array<string, int> $map
     */
    private function isExpired(array &$map, string $key): bool
    {
        if (!array_key_exists($key, $map)) {
            return false;
        }

        $expiry = $map[$key];

        if ($expiry === 0) {
            return true;
        }

        if (time() > $expiry) {
            unset($map[$key]);
            return false;
        }

        return true;
    }
}
