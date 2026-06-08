<?php

namespace Ions\Foundation;

use Ions\Providers\DatabaseProvider;

/**
 * @deprecated The DatabaseProvider now wires the DB; kept for BC.
 *
 * Existing controllers that call RegisterDB::boot() in their constructors
 * continue to work unchanged. The call is fully idempotent: repeated invocations
 * are safe because DatabaseProvider::register() guards on `!bound('db')`.
 */
class RegisterDB extends Singleton
{
    /**
     * Boot database connections by delegating to DatabaseProvider.
     *
     * @deprecated Use Ions\Providers\DatabaseProvider directly.
     */
    public static function boot(): void
    {
        $provider = new DatabaseProvider(Kernel::app());
        $provider->register();
        $provider->boot();
    }
}
