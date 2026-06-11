<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Queue\Job;
use RuntimeException;

/**
 * Test job that throws while the static $shouldFail toggle is on. Flipping the
 * toggle off lets a retried copy of the job succeed, which proves the
 * queue:retry workflow actually re-runs the job.
 */
final class FailingJob extends Job
{
    public static bool $shouldFail = true;

    /** @var list<string> */
    public static array $ran = [];

    public function __construct(private string $value)
    {
    }

    public function handle(): void
    {
        if (self::$shouldFail) {
            throw new RuntimeException('boom from FailingJob');
        }

        self::$ran[] = $this->value;
    }
}
