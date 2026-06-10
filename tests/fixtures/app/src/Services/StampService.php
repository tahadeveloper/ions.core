<?php

declare(strict_types=1);

namespace IonsFixture\Services;

/**
 * Concrete, zero-config service used by the 9.3 DI tests: the container
 * auto-wires it without any provider binding (constructor + action injection).
 */
final class StampService
{
    public function stamp(): string
    {
        return 'stamped';
    }
}
