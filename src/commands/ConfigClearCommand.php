<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * config:clear — remove the cached config file written by config:cache.
 */
class ConfigClearCommand extends Command
{
    protected $signature = 'config:clear';

    protected $description = 'Remove the cached config file';

    public function handle(): int
    {
        $file = Path::cache('config.php');

        if (is_file($file)) {
            unlink($file);
            $this->info('Config cache cleared.');
        } else {
            $this->info('Config cache is already clear.');
        }

        return self::SUCCESS;
    }
}
