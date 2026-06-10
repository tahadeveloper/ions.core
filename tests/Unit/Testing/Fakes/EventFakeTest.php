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

    expect(fn () => $fake->assertFired(EventFakeTestPing::class, fn (EventFakeTestPing $e) => $e->value === 'beta'))
        ->toThrow(AssertionFailedError::class);
});

test('assertFired fails when the event never fired', function () {
    $fake = Event::fake();

    expect(fn () => $fake->assertFired(EventFakeTestPing::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertNotFired passes when absent and fails when present', function () {
    $fake = Event::fake();

    $fake->assertNotFired(EventFakeTestPing::class);

    event(new EventFakeTestPing());

    expect(fn () => $fake->assertNotFired(EventFakeTestPing::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertNothingFired passes when quiet and fails after an event', function () {
    $fake = Event::fake();

    $fake->assertNothingFired();

    event(new EventFakeTestPing());

    expect(fn () => $fake->assertNothingFired())
        ->toThrow(AssertionFailedError::class);
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
    Event::assertNotFired('never.fired');

    expect(fn () => Event::assertNothingFired())
        ->toThrow(AssertionFailedError::class);
});

test('static assertions without an installed fake throw a helpful error', function () {
    expect(fn () => Event::assertFired(EventFakeTestPing::class))
        ->toThrow(RuntimeException::class, 'Event::fake()');
});
