<?php

namespace Ions\Security;

interface RevocationStore
{
    public function revoke(string $jti, int $ttlSeconds): void;

    public function isRevoked(string $jti): bool;
}
