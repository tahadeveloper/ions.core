<?php

declare(strict_types=1);

namespace Ions\Security;

interface RevocationStore
{
    public function revoke(string $jti, int $ttlSeconds): void;

    public function isRevoked(string $jti): bool;

    /**
     * Revoke an entire refresh-token family (lineage) by its family id (`fid`).
     *
     * Used by the breach-detection rotation pattern: when a rotated (already
     * revoked) refresh token is replayed, the whole family is revoked so every
     * sibling token derived from the same login is rejected.
     */
    public function revokeFamily(string $fid, int $ttlSeconds): void;

    public function isFamilyRevoked(string $fid): bool;
}
