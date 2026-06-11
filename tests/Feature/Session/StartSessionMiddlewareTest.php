<?php

declare(strict_types=1);

use Ions\Http\Middleware\StartSessionMiddleware;
use Ions\Session\SessionManager;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('it starts the session, attaches it to the request, and saves on the way out', function () {
    $manager = new SessionManager(['driver' => 'array']);
    $middleware = new StartSessionMiddleware($manager);

    $request = Request::create('/');

    $response = $middleware->handle($request, function (Request $req) use ($manager): Response {
        // Session is started before the controller runs.
        expect($manager->isStarted())->toBeTrue();
        // It is attached to the request.
        expect($req->hasSession())->toBeTrue()
            ->and($req->getSession())->toBe($manager->getSession());
        // Writes inside the request are visible.
        $manager->put('written', 'yes');
        return new Response('ok');
    });

    expect($response->getContent())->toBe('ok')
        ->and($manager->get('written'))->toBe('yes');
});

test('it saves the session when the pipeline throws (flash-on-error persistence)', function () {
    $manager = new SessionManager(['driver' => 'array']);
    $middleware = new StartSessionMiddleware($manager);

    expect(fn () => $middleware->handle(Request::create('/'), function () use ($manager): Response {
        expect($manager->isStarted())->toBeTrue();
        $manager->put('flashed-on-error', 'kept');

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    // MockArraySessionStorage::save() is the only thing that flips a started
    // session back to not-started — observing started=false after the throw
    // proves save() ran on the exception path. The data itself survives.
    expect($manager->isStarted())->toBeFalse()
        ->and($manager->get('flashed-on-error'))->toBe('kept');
});
