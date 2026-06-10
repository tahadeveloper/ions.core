<?php

declare(strict_types=1);

namespace IonsFixture\Services;

final class SimpleGreeter implements GreeterContract
{
    public function greet(): string
    {
        return 'hello';
    }
}
