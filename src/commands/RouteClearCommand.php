<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * route:clear — remove the compiled route cache files written by route:cache.
 */
class RouteClearCommand extends Command
{
    protected $signature = 'route:clear';

    protected $description = 'Remove the compiled route cache files';

    public function handle(): int
    {
        $removed = 0;

        foreach (['web', 'api'] as $group) {
            $file = Path::cache('routes' . DIRECTORY_SEPARATOR . $group . '.php');
            if (is_file($file)) {
                unlink($file);
                $removed++;
            }
        }

        $this->info($removed > 0 ? "Route cache cleared ({$removed} file(s) removed)." : 'Route cache is already clear.');

        return self::SUCCESS;
    }
}
