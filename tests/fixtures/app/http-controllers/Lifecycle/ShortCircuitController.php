<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Lifecycle\Recorder;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * beforeAction() short-circuit fixture: ?deny=1 returns a 403 Response —
 * the action and afterAction() are skipped, but _endState() still runs.
 */
class ShortCircuitController
{
    public function beforeAction(Request $request): ?Response
    {
        Recorder::add('beforeAction');

        if ($request->query->get('deny') === '1') {
            return new Response('denied', 403);
        }

        return null;
    }

    public function show(Request $request): Response
    {
        Recorder::add('action');

        return new Response('allowed');
    }

    public function afterAction(Request $request, Response $response): ?Response
    {
        Recorder::add('afterAction');

        return null;
    }

    public function _endState(Request $request): void
    {
        Recorder::add('_endState');
    }
}
