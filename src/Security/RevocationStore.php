<?php

declare(strict_types=1);

namespace Ions\Security;

interface RevocationStore
{
    public function revoke(string $jti, int $ttlSeconds): void;

    public function isRevoked(string $jti): bool;
}
