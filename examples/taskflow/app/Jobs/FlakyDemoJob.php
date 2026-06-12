<?php

declare(strict_types=1);

namespace App\Jobs;

use Ions\Queue\Job;
use RuntimeException;

/**
 * A deliberately failing job used to demonstrate the failed_jobs flow.
 *
 * It declares $tries = 2, so on the 'database' connection the worker releases it
 * once (attempt 1) and records it in failed_jobs on the second, final failure —
 * exactly the path exercised by queue:failed / queue:retry / queue:forget /
 * queue:flush. Dispatch it onto the database connection to see it land there:
 *
 *     dispatch((new FlakyDemoJob())->onConnection('database'));
 *     php bin/ions queue:work database --once   # twice → failed_jobs row
 */
final class FlakyDemoJob extends Job
{
    /** Attempts before the job lands in failed_jobs (wins over queue:work --tries). */
    public int $tries = 2;

    public function handle(): void
    {
        throw new RuntimeException('FlakyDemoJob always fails (demo).');
    }
}
