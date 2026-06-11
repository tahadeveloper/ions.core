<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Foundation\Kernel;
use Ions\Http\ResponseCache;
use Throwable;

/**
 * cache:clear-responses — flush the full-page response cache (Phase 12.5).
 *
 * When the configured cache store supports tags (redis/memcached) only the
 * response-cache tag is purged, leaving every other cache entry intact. When
 * it does not (the array/file stores have no tag or key-prefix scan API), the
 * documented fallback is a full flush of the default store — the command warns
 * when that happens so operators are not surprised that sibling cache data went
 * with it.
 */
class CacheClearResponsesCommand extends Command
{
    protected $signature = 'cache:clear-responses';

    protected $description = 'Flush the HTTP response cache (12.5)';

    public function handle(): int
    {
        try {
            /** @var ResponseCache $cache */
            $cache = Kernel::app()->make(ResponseCache::class);
        } catch (Throwable $e) {
            $this->error('Could not resolve the response cache: ' . $e->getMessage());

            return self::FAILURE;
        }

        $tagged = $cache->flush();

        if ($tagged) {
            $this->info('Response cache cleared (tag purge — other cache entries untouched).');
        } else {
            $this->warn('Response cache cleared via full store flush — the active cache store does not support tags, so all entries in the default store were removed.');
        }

        return self::SUCCESS;
    }
}
