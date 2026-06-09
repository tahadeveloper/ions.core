<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Queue\Job;

/**
 * Test job that records its payload when handled. Used to prove sync dispatch
 * runs immediately and database dispatch is deferred until a worker runs it.
 */
final class RecordingJob extends Job
{
    /** @var list<string> */
    public static array $ran = [];

    public function __construct(private string $value)
    {
    }

    public function handle(): void
    {
        self::$ran[] = $this->value;
    }
}
