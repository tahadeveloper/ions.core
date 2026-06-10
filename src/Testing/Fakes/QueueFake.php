<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Illuminate\Support\Testing\Fakes\QueueFake as IlluminateQueueFake;
use PHPUnit\Framework\Assert;

/**
 * Recording queue fake for tests, installed via {@see \Ions\Support\Queue::fake()}.
 *
 * Extends Illuminate's QueueFake — itself a QueueManager — so the container's
 * 'queue' binding (and the dispatch() helper, which resolves it and calls
 * connection()->push()) routes every job here instead of a real driver. Jobs
 * are recorded, never run.
 *
 * The Ions-flavoured assertions below inspect the recorded jobs directly and
 * fail with Ions-worded messages ("dispatched", never Laravel's "pushed");
 * the inherited Illuminate assertPushed* family remains available too. Every
 * assertion returns $this so assertions chain.
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
    public function assertDispatched(string $job, callable|int|null $filterOrCount = null): static
    {
        if (is_int($filterOrCount)) {
            return $this->assertDispatchedTimes($job, $filterOrCount);
        }

        $dispatched = $this->pushed($job)->count();

        Assert::assertTrue($dispatched > 0, sprintf(
            'Expected job [%s] to be dispatched, but it was not.%s',
            $job,
            $this->dispatchedJobsSummary()
        ));

        if ($filterOrCount !== null) {
            Assert::assertTrue(
                $this->pushed($job, $filterOrCount)->isNotEmpty(),
                sprintf(
                    'Job [%s] was dispatched %d time(s), but no dispatched job matched the given filter.',
                    $job,
                    $dispatched
                )
            );
        }

        return $this;
    }

    /**
     * Assert a job of the given class was dispatched exactly $times times.
     *
     * @param class-string $job
     */
    public function assertDispatchedTimes(string $job, int $times = 1): static
    {
        $dispatched = $this->pushed($job)->count();

        if ($dispatched === 0 && $times !== 0) {
            Assert::fail(sprintf(
                'Expected job [%s] to be dispatched %d time(s), but it was never dispatched.%s',
                $job,
                $times,
                $this->dispatchedJobsSummary()
            ));
        }

        Assert::assertTrue($dispatched === $times, sprintf(
            'Expected job [%s] to be dispatched %d time(s), but it was dispatched %d time(s).',
            $job,
            $times,
            $dispatched
        ));

        return $this;
    }

    /**
     * Assert no job of the given class was dispatched (or none matching the
     * filter callable).
     *
     * @param class-string $job
     */
    public function assertNotDispatched(string $job, ?callable $filter = null): static
    {
        $dispatched = $this->pushed($job)->count();

        if ($filter === null) {
            Assert::assertTrue($dispatched === 0, sprintf(
                'Expected job [%s] not to be dispatched, but it was dispatched %d time(s).',
                $job,
                $dispatched
            ));

            return $this;
        }

        $matched = $this->pushed($job, $filter)->count();

        Assert::assertTrue($matched === 0, sprintf(
            'Expected no dispatched job [%s] to match the given filter, but %d of %d did.',
            $job,
            $matched,
            $dispatched
        ));

        return $this;
    }

    /**
     * Assert no jobs were dispatched at all.
     */
    public function assertNothingDispatched(): static
    {
        $classes = array_map('strval', array_keys($this->pushedJobs()));
        $raw = count($this->rawPushes());

        $summary = $classes === [] ? '' : sprintf(' Dispatched jobs: [%s].', implode(', ', $classes));

        if ($raw > 0) {
            $summary .= sprintf(' %d raw payload push(es) recorded.', $raw);
        }

        Assert::assertTrue(
            $classes === [] && $raw === 0,
            'Expected no jobs to be dispatched, but some were.' . $summary
        );

        return $this;
    }

    /**
     * Ions-worded summary of every recorded job class, to distinguish "this
     * job never dispatched" from "nothing dispatched at all" in failures.
     */
    private function dispatchedJobsSummary(): string
    {
        $classes = array_map('strval', array_keys($this->pushedJobs()));

        return $classes === []
            ? ' No jobs were dispatched at all.'
            : sprintf(' Dispatched jobs: [%s].', implode(', ', $classes));
    }
}
