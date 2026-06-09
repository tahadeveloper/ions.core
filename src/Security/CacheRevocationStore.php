<?php

declare(strict_types=1);

namespace Ions\Security;

use Illuminate\Contracts\Cache\Repository;

final class CacheRevocationStore implements RevocationStore
{
    public function __construct(private Repository $cache, private string $prefix = 'jwt_revoked:')
    {
    }

    public function revoke(string $jti, int $ttlSeconds): void
    {
        $this->cache->put($this->prefix . $jti, true, max(1, $ttlSeconds));
    }

    public function isRevoked(string $jti): bool
    {
        return (bool) $this->cache->get($this->prefix . $jti, false);
    }
}
