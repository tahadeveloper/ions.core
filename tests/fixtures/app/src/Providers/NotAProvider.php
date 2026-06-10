<?php

declare(strict_types=1);

namespace IonsFixture\Providers;

/**
 * Plain class living in the Providers folder: discovery must SKIP anything
 * that does not extend Ions\Container\ServiceProvider.
 */
class NotAProvider
{
    public function register(): void
    {
        // Never called by the framework — not a ServiceProvider.
    }
}
