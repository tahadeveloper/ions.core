<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * discover:clear — remove the cached provider list written by discover:cache,
 * returning boot to live discovery (defaults + composer + host scans).
 */
class DiscoverClearCommand extends Command
{
    protected $signature = 'discover:clear';

    protected $description = 'Remove the cached provider discovery file';

    public function handle(): int
    {
        $file = Path::cache('providers.php');

        if (is_file($file)) {
            unlink($file);
            $this->info('Provider cache cleared.');
        } else {
            $this->info('Provider cache is already clear.');
        }

        return self::SUCCESS;
    }
}
