<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;

/**
 * optimize — build every production cache in one shot:
 *   - route:cache  (compiled route matchers)
 *   - config:cache (merged config array)
 *
 * Run it on deploy; run optimize:clear to drop all caches again.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'optimize';

    protected $description = 'Cache routes and config for production';

    public function handle(): int
    {
        $this->line('Optimizing the application...');

        if ($this->call('route:cache') !== self::SUCCESS) {
            $this->error('optimize aborted: route:cache failed.');

            return self::FAILURE;
        }

        if ($this->call('config:cache') !== self::SUCCESS) {
            $this->error('optimize aborted: config:cache failed.');

            return self::FAILURE;
        }

        $this->info('Application optimized: route + config caches written.');

        return self::SUCCESS;
    }
}
