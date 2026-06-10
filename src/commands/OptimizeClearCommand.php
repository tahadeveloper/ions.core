<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * optimize:clear — remove every cache optimize/route:cache/config:cache wrote,
 * plus the compiled Twig template cache.
 */
class OptimizeClearCommand extends Command
{
    protected $signature = 'optimize:clear';

    protected $description = 'Remove the route, config and Twig caches';

    public function handle(): int
    {
        $this->call('route:clear');
        $this->call('config:clear');

        $twigCache = Path::cache('twig');
        if (is_dir($twigCache)) {
            $this->removeDirectory($twigCache);
            $this->info('Twig cache cleared.');
        }

        $this->info('All optimization caches cleared.');

        return self::SUCCESS;
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
