<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Illuminate\Support\Testing\Fakes\EventFake as IlluminateEventFake;
use PHPUnit\Framework\Assert;

/**
 * Recording event fake for tests, installed via {@see \Ions\Support\Event::fake()}.
 *
 * Extends Illuminate's EventFake: faked events are recorded instead of being
 * dispatched to their listeners; when constructed with an explicit list of
 * events to fake, all other events are forwarded to the wrapped real
 * dispatcher (so their listeners still run).
 *
 * The Ions-flavoured assertions below inspect the recorded events directly
 * and fail with Ions-worded messages ("fired", never Laravel's "dispatched");
 * the inherited Illuminate assertDispatched* family remains available too.
 * Every assertion returns $this so assertions chain.
 */
class EventFake extends IlluminateEventFake
{
    /**
     * Assert the given event fired (optionally at least one occurrence
     * matching the filter callable, which receives the event object).
     *
     * @param class-string|string $event Event class or string event name.
     */
    public function assertFired(string $event, ?callable $filter = null): static
    {
        $fired = $this->dispatched($event)->count();

        Assert::assertTrue($fired > 0, sprintf(
            'Expected event [%s] to fire, but it did not.%s',
            $event,
            $this->firedEventsSummary()
        ));

        if ($filter !== null) {
            Assert::assertTrue(
                $this->dispatched($event, $filter)->isNotEmpty(),
                sprintf(
                    'Event [%s] fired %d time(s), but no occurrence matched the given filter.',
                    $event,
                    $fired
                )
            );
        }

        return $this;
    }

    /**
     * Assert the given event fired exactly $times times.
     *
     * @param class-string|string $event Event class or string event name.
     */
    public function assertFiredTimes(string $event, int $times = 1): static
    {
        $fired = $this->dispatched($event)->count();

        if ($fired === 0 && $times !== 0) {
            Assert::fail(sprintf(
                'Expected event [%s] to fire %d time(s), but it never fired.%s',
                $event,
                $times,
                $this->firedEventsSummary()
            ));
        }

        Assert::assertTrue($fired === $times, sprintf(
            'Expected event [%s] to fire %d time(s), but it fired %d time(s).',
            $event,
            $times,
            $fired
        ));

        return $this;
    }

    /**
     * Assert the given event did not fire (or no occurrence matched the
     * filter callable).
     *
     * @param class-string|string $event Event class or string event name.
     */
    public function assertNotFired(string $event, ?callable $filter = null): static
    {
        $fired = $this->dispatched($event)->count();

        if ($filter === null) {
            Assert::assertTrue($fired === 0, sprintf(
                'Expected event [%s] not to fire, but it fired %d time(s).',
                $event,
                $fired
            ));

            return $this;
        }

        $matched = $this->dispatched($event, $filter)->count();

        Assert::assertTrue($matched === 0, sprintf(
            'Expected no occurrence of event [%s] to match the given filter, but %d of %d did.',
            $event,
            $matched,
            $fired
        ));

        return $this;
    }

    /**
     * Assert no events were recorded by the fake at all.
     */
    public function assertNothingFired(): static
    {
        $names = array_map('strval', array_keys($this->dispatchedEvents()));

        Assert::assertTrue(
            $names === [],
            $names === []
                ? 'Expected no events to fire.'
                : sprintf('Expected no events to fire, but these did: [%s].', implode(', ', $names))
        );

        return $this;
    }

    /**
     * Ions-worded summary of every recorded event, to distinguish "this event
     * never fired" from "nothing fired at all" in failures.
     */
    private function firedEventsSummary(): string
    {
        $names = array_map('strval', array_keys($this->dispatchedEvents()));

        return $names === []
            ? ' No events were fired at all.'
            : sprintf(' Fired events: [%s].', implode(', ', $names));
    }
}
