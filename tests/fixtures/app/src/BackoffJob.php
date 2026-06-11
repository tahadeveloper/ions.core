<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Queue\Job;
use RuntimeException;

/**
 * Always-failing job declaring $backoff = 30 on the class. Used to prove a
 * released job's next attempt is delayed: the re-queued jobs row must carry
 * available_at ~30s in the future (asserted from the row — no sleeping).
 */
final class BackoffJob extends Job
{
    public int $tries = 2;

    public int $backoff = 30;

    public function handle(): void
    {
        throw new RuntimeException('boom from BackoffJob');
    }
}
