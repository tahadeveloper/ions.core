<?php

declare(strict_types=1);

namespace Ions\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Ions\Foundation\Kernel;
use Ions\Testing\Fakes\EventFake;
use RuntimeException;

/**
 * Thin static facade over the container-bound event dispatcher ('events',
 * bound by {@see \Ions\Providers\EventProvider}).
 *
 * In tests, {@see self::fake()} swaps the binding for a recording
 * {@see EventFake}; events fired through the event() helper — including the
 * kernel's own RequestHandled — are then recorded instead of dispatched, and
 * the assertion passthroughs below resolve the installed fake. The container
 * is rebuilt per test boot, so an installed fake never leaks into the next
 * test.
 */
final class Event
{
    /**
     * The container-bound event dispatcher (the fake, once installed).
     */
    public static function dispatcher(): Dispatcher
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = Kernel::app()->get('events');

        return $dispatcher;
    }

    /**
     * Swap the 'events' binding for a recording fake and return it.
     *
     * With no argument every event is recorded and no listener runs. With a
     * list of event names only those are intercepted — all other events are
     * forwarded to the real dispatcher and their listeners still run.
     *
     * @param list<class-string|string>|null $eventsToFake
     */
    public static function fake(?array $eventsToFake = null): EventFake
    {
        $fake = new EventFake(self::dispatcher(), $eventsToFake ?? []);

        Kernel::app()->instance('events', $fake);

        return $fake;
    }

    /**
     * @param class-string|string $event
     *
     * @see EventFake::assertFired()
     */
    public static function assertFired(string $event, ?callable $filter = null): void
    {
        self::installedFake()->assertFired($event, $filter);
    }

    /**
     * @param class-string|string $event
     *
     * @see EventFake::assertFiredTimes()
     */
    public static function assertFiredTimes(string $event, int $times = 1): void
    {
        self::installedFake()->assertFiredTimes($event, $times);
    }

    /**
     * @param class-string|string $event
     *
     * @see EventFake::assertNotFired()
     */
    public static function assertNotFired(string $event, ?callable $filter = null): void
    {
        self::installedFake()->assertNotFired($event, $filter);
    }

    /**
     * @see EventFake::assertNothingFired()
     */
    public static function assertNothingFired(): void
    {
        self::installedFake()->assertNothingFired();
    }

    /**
     * The fake currently bound as 'events', or a hard failure pointing at the
     * missing Event::fake() call.
     */
    private static function installedFake(): EventFake
    {
        $events = Kernel::app()->get('events');

        if (!$events instanceof EventFake) {
            throw new RuntimeException(
                'Event assertions require the fake: call Event::fake() in your test before firing events.'
            );
        }

        return $events;
    }
}
