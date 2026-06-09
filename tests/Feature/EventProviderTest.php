<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Ions\Events\RequestHandled;
use Ions\Foundation\Kernel;

beforeEach(fn () => bootFixtureKernel());

test('the event dispatcher is bound in the container', function () {
    expect(Kernel::app()->has('events'))->toBeTrue()
        ->and(Kernel::app()->get('events'))->toBeInstanceOf(Dispatcher::class);
});

test('the events binding is a singleton', function () {
    expect(Kernel::app()->get('events'))->toBe(Kernel::app()->get('events'));
});

test('listen() registers a listener that event() fires', function () {
    $seen = [];
    listen('my.event', function ($value) use (&$seen) {
        $seen[] = $value;
    });

    event('my.event', ['hello']);

    expect($seen)->toBe(['hello']);
});

test('listeners declared in config(events.listen) are auto-registered', function () {
    // The fixture config/events.php maps RequestHandled to a recording listener.
    \IonsFixture\RecordingListener::$handled = [];

    $request = \Ions\Support\Request::create('/');
    $response = new \Symfony\Component\HttpFoundation\Response('ok');
    event(new RequestHandled($request, $response));

    expect(\IonsFixture\RecordingListener::$handled)->toHaveCount(1)
        ->and(\IonsFixture\RecordingListener::$handled[0])->toBeInstanceOf(RequestHandled::class);
});

test('Kernel::handle() fires RequestHandled through the dispatcher', function () {
    \IonsFixture\RecordingListener::$handled = [];

    Kernel::handle(\Ions\Support\Request::create('/no-such-route'));

    expect(\IonsFixture\RecordingListener::$handled)->toHaveCount(1)
        ->and(\IonsFixture\RecordingListener::$handled[0])->toBeInstanceOf(RequestHandled::class);
});
