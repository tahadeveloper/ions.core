<?php

namespace Ions\Security;

final class Claims
{
    public function __construct(public string $userId, public array $all = [])
    {
    }
}
