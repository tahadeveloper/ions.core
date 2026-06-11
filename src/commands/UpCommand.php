<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Foundation\MaintenanceMode;

/**
 * up — bring the application out of maintenance mode (10.8).
 *
 * Removes var/maintenance.php (written by `ions down`). A no-op with a
 * friendly note when the app is already live.
 */
class UpCommand extends Command
{
    protected $signature = 'up';

    protected $description = 'Bring the application out of maintenance mode';

    public function handle(): int
    {
        if (!MaintenanceMode::active()) {
            $this->info('Application is not in maintenance mode.');

            return self::SUCCESS;
        }

        if (!MaintenanceMode::deactivate()) {
            $this->error('Could not remove ' . MaintenanceMode::path() . ' — check permissions.');

            return self::FAILURE;
        }

        $this->info('Application is now live.');

        return self::SUCCESS;
    }
}
