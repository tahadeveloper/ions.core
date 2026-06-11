<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Queue\Job;
use RuntimeException;

/**
 * Always-failing job declaring $tries = 2 on the class. Used to prove the job
 * property wins over the queue:work --tries CLI default (Illuminate worker
 * semantics: a payload maxTries overrides WorkerOptions::$maxTries).
 */
final class LimitedTriesJob extends Job
{
    public int $tries = 2;

    public function handle(): void
    {
        throw new RuntimeException('boom from LimitedTriesJob');
    }
}
