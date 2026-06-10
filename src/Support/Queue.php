<?php

declare(strict_types=1);

namespace Ions\Support;

use Illuminate\Queue\QueueManager;
use Ions\Foundation\Kernel;
use Ions\Support\Concerns\ResolvesFake;
use Ions\Testing\Fakes\QueueFake;

/**
 * Thin static facade over the container-bound queue manager ('queue', bound
 * by {@see \Ions\Providers\QueueProvider}).
 *
 * In tests, {@see self::fake()} swaps the binding for a recording
 * {@see QueueFake}; jobs dispatched through the dispatch() helper are then
 * recorded instead of run, and the assertion passthroughs below resolve the
 * installed fake. The container is rebuilt per test boot, so an installed
 * fake never leaks into the next test.
 */
final class Queue
{
    use ResolvesFake;

    /**
     * The container-bound queue manager (the fake, once installed).
     */
    public static function manager(): QueueManager
    {
        /** @var QueueManager $manager */
        $manager = Kernel::app()->get('queue');

        return $manager;
    }

    /**
     * Swap the 'queue' binding for a recording fake and return it. Subsequent
     * dispatch() calls are recorded instead of pushed to a real driver.
     *
     * @param list<class-string> $jobsToFake Limit faking to these job classes;
     *                                       all other jobs hit the real queue.
     */
    public static function fake(array $jobsToFake = []): QueueFake
    {
        // QueueFake (a QueueManager) only consumes its $app via array-access
        // for config bindings — all resolvable from the Ions container.
        /** @phpstan-ignore argument.type */
        $fake = new QueueFake(Kernel::app(), $jobsToFake, self::manager());

        return self::installFake('queue', $fake);
    }

    /**
     * @param class-string $job
     *
     * @see QueueFake::assertDispatched()
     */
    public static function assertDispatched(string $job, callable|int|null $filterOrCount = null): void
    {
        self::installedFake()->assertDispatched($job, $filterOrCount);
    }

    /**
     * @param class-string $job
     *
     * @see QueueFake::assertDispatchedTimes()
     */
    public static function assertDispatchedTimes(string $job, int $times = 1): void
    {
        self::installedFake()->assertDispatchedTimes($job, $times);
    }

    /**
     * @param class-string $job
     *
     * @see QueueFake::assertNotDispatched()
     */
    public static function assertNotDispatched(string $job, ?callable $filter = null): void
    {
        self::installedFake()->assertNotDispatched($job, $filter);
    }

    /**
     * @see QueueFake::assertNothingDispatched()
     */
    public static function assertNothingDispatched(): void
    {
        self::installedFake()->assertNothingDispatched();
    }

    /**
     * The fake currently bound as 'queue', or a hard failure pointing at the
     * missing Queue::fake() call (without lazily building the real manager).
     */
    private static function installedFake(): QueueFake
    {
        return self::resolveInstalledFake('queue', QueueFake::class, 'Queue');
    }
}
