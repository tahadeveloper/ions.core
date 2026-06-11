<?php

declare(strict_types=1);

namespace Ions\Security;

use Illuminate\Contracts\Cache\Repository;

final class CacheRevocationStore implements RevocationStore
{
    public function __construct(
        private Repository $cache,
        private string $prefix = 'jwt_revoked:',
        private string $familyPrefix = 'jwt_revoked_family:',
    ) {
    }

    public function revoke(string $jti, int $ttlSeconds): void
    {
        $this->cache->put($this->prefix . $jti, true, max(1, $ttlSeconds));
    }

    public function isRevoked(string $jti): bool
    {
        return (bool) $this->cache->get($this->prefix . $jti, false);
    }

    public function revokeFamily(string $fid, int $ttlSeconds): void
    {
        $this->cache->put($this->familyPrefix . $fid, true, max(1, $ttlSeconds));
    }

    public function isFamilyRevoked(string $fid): bool
    {
        return (bool) $this->cache->get($this->familyPrefix . $fid, false);
    }
}
