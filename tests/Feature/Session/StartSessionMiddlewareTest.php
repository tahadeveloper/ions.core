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
