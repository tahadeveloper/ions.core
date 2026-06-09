<?php

declare(strict_types=1);

namespace Ions\Security;

final class Claims
{
    /** @param array<non-empty-string,mixed> $all */
    public function __construct(public string $userId, public array $all = [])
    {
    }
}
