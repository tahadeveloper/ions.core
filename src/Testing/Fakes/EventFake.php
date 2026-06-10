<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Illuminate\Support\Testing\Fakes\EventFake as IlluminateEventFake;

/**
 * Recording event fake for tests, installed via {@see \Ions\Support\Event::fake()}.
 *
 * Extends Illuminate's EventFake: faked events are recorded instead of being
 * dispatched to their listeners; when constructed with an explicit list of
 * events to fake, all other events are forwarded to the wrapped real
 * dispatcher (so their listeners still run).
 *
 * The Ions-flavoured assertion names below delegate to the inherited
 * Illuminate assertions (assertDispatched & co., which remain available too).
 */
class EventFake extends IlluminateEventFake
{
    /**
     * Assert the given event fired (optionally at least one occurrence
     * matching the filter callable, which receives the event object).
     *
     * @param class-string|string $event Event class or string event name.
     */
    public function assertFired(string $event, ?callable $filter = null): void
    {
        $this->assertDispatched($event, $filter);
    }

    /**
     * Assert the given event did not fire (or no occurrence matched the
     * filter callable).
     *
     * @param class-string|string $event Event class or string event name.
     */
    public function assertNotFired(string $event, ?callable $filter = null): void
    {
        $this->assertNotDispatched($event, $filter);
    }

    /**
     * Assert no events were recorded by the fake at all.
     */
    public function assertNothingFired(): void
    {
        $this->assertNothingDispatched();
    }
}
