<?php

/**
 * Phase 8.2 — experimental WorkerRunner: boot once, handle many requests with
 * per-request isolation (resetForRequest between iterations).
 */

use Ions\Runtime\WorkerRunner;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => bootFixtureKernel());

test('the worker loop handles sequential requests and isolation holds between them', function () {
    $queue = [
        Request::create('/session-write'), // writes session('leak') = 'r1'
        Request::create('/session-read'),  // must NOT see it -> 'clean'
        Request::create('/ping'),
    ];

    $responses = [];

    $handled = (new WorkerRunner())->run(
        function () use (&$queue): ?Request {
            return array_shift($queue); // null when empty = stop
        },
        function (Response $response) use (&$responses): void {
            $responses[] = $response;
        },
    );

    expect($handled)->toBe(3)
        ->and($responses)->toHaveCount(3)
        ->and($responses[0]->getContent())->toBe('written')
        // The second request runs with a FRESH session — no bleed from request 1.
        ->and($responses[1]->getContent())->toBe('clean')
        ->and($responses[2]->getContent())->toBe('pong')
        ->and($responses[0]->getStatusCode())->toBe(200)
        ->and($responses[1]->getStatusCode())->toBe(200);
});

test('maxRequests stops the loop even when the provider keeps supplying requests', function () {
    $provided = 0;
    $emitted = 0;

    $handled = (new WorkerRunner())->run(
        function () use (&$provided): ?Request {
            $provided++;

            return Request::create('/ping'); // never runs dry
        },
        function (Response $response) use (&$emitted): void {
            $emitted++;
        },
        maxRequests: 2,
    );

    expect($handled)->toBe(2)
        ->and($emitted)->toBe(2)
        ->and($provided)->toBe(2);
});

test('a null from the provider stops the loop immediately', function () {
    $handled = (new WorkerRunner())->run(
        fn (): ?Request => null,
        function (Response $response): void {
            throw new RuntimeException('emitter must not be called');
        },
    );

    expect($handled)->toBe(0);
});

test('worker iterations do not leak the shared response between requests', function () {
    $queue = [
        Request::create('/leak-header'),     // mutates the shared response
        Request::create('/shared-response'), // must get a fresh shared response
    ];

    $responses = [];

    (new WorkerRunner())->run(
        function () use (&$queue): ?Request {
            return array_shift($queue);
        },
        function (Response $response) use (&$responses): void {
            $responses[] = $response;
        },
    );

    expect($responses[0]->headers->get('X-Leak'))->toBe('r1')
        ->and($responses[1]->headers->has('X-Leak'))->toBeFalse()
        ->and($responses[1]->getContent())->toBe('shared');
});
