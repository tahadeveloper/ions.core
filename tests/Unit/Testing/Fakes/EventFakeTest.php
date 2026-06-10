<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Ions\Foundation\Kernel;
use Ions\Support\Event;
use Ions\Testing\Fakes\EventFake;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(fn () => bootFixtureKernel());

/** A tiny event class local to this file. */
final class EventFakeTestPing
{
    public function __construct(public string $value = 'ping')
    {
    }
}

test('Event::fake() swaps the container binding and returns the fake', function () {
    $fake = Event::fake();

    expect($fake)->toBeInstanceOf(EventFake::class)
        ->and(Kernel::app()->get('events'))->toBe($fake)
        ->and(Kernel::app()->get(DispatcherContract::class))->toBe($fake);
});

test('faked events are recorded instead of reaching their listeners', function () {
    $heard = [];
    listen(EventFakeTestPing::class, function (EventFakeTestPing $e) use (&$heard) {
        $heard[] = $e->value;
    });

    $fake = Event::fake();

    event(new EventFakeTestPing('muted'));

    $fake->assertFired(EventFakeTestPing::class);
    expect($heard)->toBe([]);
});

test('assertFired accepts a filter callable receiving the event', function () {
    $fake = Event::fake();

    event(new EventFakeTestPing('alpha'));

    $fake->assertFired(EventFakeTestPing::class, fn (EventFakeTestPing $e) => $e->value === 'alpha');

    // fired-but-unmatched is distinguished from never-fired
    expect(fn () => $fake->assertFired(EventFakeTestPing::class, fn (EventFakeTestPing $e) => $e->value === 'beta'))
        ->toThrow(
            AssertionFailedError::class,
            'Event [EventFakeTestPing] fired 1 time(s), but no occurrence matched the given filter.'
        );
});

test('assertFired fails with an Ions-worded message when nothing fired at all', function () {
    $fake = Event::fake();

    expect(fn () => $fake->assertFired(EventFakeTestPing::class))
        ->toThrow(
            AssertionFailedError::class,
            'Expected event [EventFakeTestPing] to fire, but it did not. No events were fired at all.'
        );
});

test('assertFired failures list the events that fired instead', function () {
    $fake = Event::fake();

    event(new EventFakeTestPing());

    expect(fn () => $fake->assertFired('never.fired'))
        ->toThrow(
            AssertionFailedError::class,
            'Expected event [never.fired] to fire, but it did not. Fired events: [EventFakeTestPing].'
        );
});

test('assertFiredTimes passes and fails on the exact count with an Ions-worded message', function () {
    $fake = Event::fake();

    event(new EventFakeTestPing('one'));
    event(new EventFakeTestPing('two'));

    $fake->assertFiredTimes(EventFakeTestPing::class, 2);

    expect(fn () => $fake->assertFiredTimes(EventFakeTestPing::class, 3))
        ->toThrow(
            AssertionFailedError::class,
            'Expected event [EventFakeTestPing] to fire 3 time(s), but it fired 2 time(s).'
        );

    expect(fn () => $fake->assertFiredTimes('never.fired'))
        ->toThrow(
            AssertionFailedError::class,
            'Expected event [never.fired] to fire 1 time(s), but it never fired. Fired events: [EventFakeTestPing].'
        );
});

test('assertNotFired passes when absent and fails when present', function () {
    $fake = Event::fake();

    $fake->assertNotFired(EventFakeTestPing::class);

    event(new EventFakeTestPing());

    expect(fn () => $fake->assertNotFired(EventFakeTestPing::class))
        ->toThrow(
            AssertionFailedError::class,
            'Expected event [EventFakeTestPing] not to fire, but it fired 1 time(s).'
        );

    expect(fn () => $fake->assertNotFired(EventFakeTestPing::class, fn () => true))
        ->toThrow(
            AssertionFailedError::class,
            'Expected no occurrence of event [EventFakeTestPing] to match the given filter, but 1 of 1 did.'
        );
});

test('assertNothingFired passes when quiet and fails listing what fired', function () {
    $fake = Event::fake();

    $fake->assertNothingFired();

    event(new EventFakeTestPing());

    expect(fn () => $fake->assertNothingFired())
        ->toThrow(
            AssertionFailedError::class,
            'Expected no events to fire, but these did: [EventFakeTestPing].'
        );
});

test('the Ions assertions chain by returning the fake', function () {
    $fake = Event::fake();

    expect($fake->assertNothingFired())->toBe($fake);

    event(new EventFakeTestPing('chain'));

    expect($fake->assertFired(EventFakeTestPing::class))->toBe($fake)
        ->and($fake->assertFiredTimes(EventFakeTestPing::class))->toBe($fake)
        ->and($fake->assertNotFired('never.fired'))->toBe($fake);
});

test('a partial fake only intercepts the listed events; others still fire', function () {
    $heard = [];
    listen('other.event', function (...$payload) use (&$heard) {
        $heard[] = $payload;
    });

    $fake = Event::fake([EventFakeTestPing::class]);

    event(new EventFakeTestPing('faked'));
    event('other.event', ['real']);

    $fake->assertFired(EventFakeTestPing::class);
    $fake->assertNotFired('other.event');
    expect($heard)->toBe([['real']]);
});

test('assertions are reachable statically through the facade', function () {
    Event::fake();

    event(new EventFakeTestPing());

    Event::assertFired(EventFakeTestPing::class);
    Event::assertFiredTimes(EventFakeTestPing::class);
    Event::assertNotFired('never.fired');

    expect(fn () => Event::assertNothingFired())
        ->toThrow(AssertionFailedError::class);
});

test('static assertions without an installed fake throw a helpful error', function () {
    expect(fn () => Event::assertFired(EventFakeTestPing::class))
        ->toThrow(RuntimeException::class, 'Event::fake()');
});
