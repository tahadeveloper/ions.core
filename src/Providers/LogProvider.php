<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Log\LogManager;

/**
 * Binds the channel-based {@see LogManager} into the container as 'log'.
 *
 * Lazy singleton: nothing is opened until the first channel is actually
 * resolved (via the {@see \Ions\Support\Log} facade or a direct 'log'
 * resolve). Channel definitions come from config('logging.*'); a missing
 * config/logging.php falls back to the built-in 'app' single-file channel,
 * so zero-config hosts keep logging to var/logs/app.log.
 */
final class LogProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->container->bound('log')) {
            return;
        }

        $this->container->singleton('log', static fn (): LogManager => new LogManager());
    }
}
