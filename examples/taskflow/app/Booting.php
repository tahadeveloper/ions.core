<?php

declare(strict_types=1);

namespace App;

/**
 * Optional boot hook: Kernel::boot() calls App\Booting::boot() (when the
 * class exists) after the service providers have booted. Register container
 * bindings, event listeners or early routes here.
 */
class Booting
{
    public static function boot(): void
    {
        // Application-level setup. Taskflow's policies/gates and providers
        // are wired in later sub-phases (13.3+).
    }
}
