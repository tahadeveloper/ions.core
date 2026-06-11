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
 * Security-isolated by default. When the configured store supports tags
 * (redis/memcached) only the response-cache tag is purged, leaving every other
 * cache entry intact.
 *
 * When the store has NO tag support (file/database — no key-prefix scan), the
 * only way to clear it is a full store flush. But with skeleton defaults the
 * response cache, the JWT revocation store AND the rate-limit/forgot throttles
 * all share ONE store, so that full flush would un-revoke logged-out JWTs and
 * reset throttles. The command therefore REFUSES the destructive flush unless
 * the operator passes --force, naming exactly what else gets wiped — a routine
 * response-cache purge can never silently clear security state.
 */
class CacheClearResponsesCommand extends Command
{
    protected $signature = 'cache:clear-responses {--force : Allow a full store flush on a non-tag store, ALSO wiping JWT revocations and rate-limit/forgot throttles that share the store}';

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

        $force = (bool) $this->option('force');
        $result = $cache->flush($force);

        return match ($result) {
            ResponseCache::FLUSH_TAGGED => $this->reportTagged(),
            ResponseCache::FLUSH_FORCED => $this->reportForced(),
            default => $this->reportRefused(),
        };
    }

    private function reportTagged(): int
    {
        $this->info('Response cache cleared (tag purge — other cache entries untouched).');

        return self::SUCCESS;
    }

    private function reportForced(): int
    {
        $this->warn('Response cache cleared via a FULL store flush (--force): the active store has no tag support, so EVERYTHING in it was removed — including JWT revocations and rate-limit/forgot-password throttles that share this store. Logged-out tokens may now be accepted again and throttle counters are reset.');

        return self::SUCCESS;
    }

    private function reportRefused(): int
    {
        $this->warn('Response cache NOT cleared: the active cache store has no tag support, so the only way to purge it is a full store flush — which would ALSO wipe JWT revocations and rate-limit/forgot-password throttles sharing the store (un-revoking logged-out tokens, resetting throttles). Nothing was touched.');
        $this->line('Re-run with --force to accept that blast radius, or point cache.default (or a dedicated response store) at redis/memcached for a targeted tag purge.');

        return self::SUCCESS;
    }
}
