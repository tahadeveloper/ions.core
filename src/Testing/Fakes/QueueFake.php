<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Illuminate\Support\Testing\Fakes\QueueFake as IlluminateQueueFake;

/**
 * Recording queue fake for tests, installed via {@see \Ions\Support\Queue::fake()}.
 *
 * Extends Illuminate's QueueFake — itself a QueueManager — so the container's
 * 'queue' binding (and the dispatch() helper, which resolves it and calls
 * connection()->push()) routes every job here instead of a real driver. Jobs
 * are recorded, never run.
 *
 * The Ions-flavoured assertion names below delegate to the inherited
 * Illuminate assertions (assertPushed & co., which remain available too).
 */
class QueueFake extends IlluminateQueueFake
{
    /**
     * Assert a job of the given class was dispatched. The second argument is
     * either a filter callable receiving each dispatched job (at least one
     * must match) or an int asserting the exact dispatch count.
     *
     * @param class-string $job
     */
    public function assertDispatched(string $job, callable|int|null $filterOrCount = null): void
    {
        if (is_int($filterOrCount)) {
            $this->assertPushedTimes($job, $filterOrCount);

            return;
        }

        $this->assertPushed($job, $filterOrCount);
    }

    /**
     * Assert a job of the given class was dispatched exactly $times times.
     *
     * @param class-string $job
     */
    public function assertDispatchedTimes(string $job, int $times = 1): void
    {
        $this->assertPushedTimes($job, $times);
    }

    /**
     * Assert no job of the given class was dispatched (or none matching the
     * filter callable).
     *
     * @param class-string $job
     */
    public function assertNotDispatched(string $job, ?callable $filter = null): void
    {
        $this->assertNotPushed($job, $filter);
    }

    /**
     * Assert no jobs were dispatched at all.
     */
    public function assertNothingDispatched(): void
    {
        $this->assertNothingPushed();
    }
}
