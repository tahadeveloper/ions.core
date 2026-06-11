<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Foundation\MaintenanceMode;

/**
 * down — put the application into maintenance mode (10.8).
 *
 * Writes var/maintenance.php; Kernel::handle() answers every request with a
 * themeable 503 (errors/503.twig / JSON for api) until `ions up`. The /up
 * liveness probe stays reachable.
 *
 * --retry=N   adds a Retry-After: N header to the 503.
 * --secret=S  enables the bypass flow: visiting /{S} sets the
 *             ions_maintenance cookie (sha256 of the secret) and redirects
 *             to '/'; cookied requests then pass through normally.
 */
class DownCommand extends Command
{
    protected $signature = 'down
        {--secret= : Bypass secret — visiting /{secret} sets a bypass cookie}
        {--retry= : Seconds clients should wait (Retry-After header)}';

    protected $description = 'Put the application into maintenance mode (503 until `up`)';

    public function handle(): int
    {
        $secret = $this->option('secret');
        $secret = is_string($secret) && $secret !== '' ? $secret : null;

        $retry = $this->option('retry');
        $retry = is_numeric($retry) ? (int) $retry : null;

        MaintenanceMode::activate($secret, $retry);

        $this->info('Application is now in maintenance mode.');

        if ($retry !== null) {
            $this->line("Clients are told to retry after {$retry} seconds (Retry-After).");
        }

        if ($secret !== null) {
            $this->line("Bypass: visit /{$secret} to set the bypass cookie and browse normally.");
        }

        $this->line('Run `ions up` to go live again.');

        return self::SUCCESS;
    }
}
