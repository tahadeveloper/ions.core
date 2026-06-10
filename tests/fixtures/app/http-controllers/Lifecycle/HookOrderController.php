<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Lifecycle\Recorder;
use IonsFixture\Middleware\RecordingOrderMiddleware;
use IonsFixture\Services\StampService;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Order-recording fixture for the FULL 9.3 lifecycle:
 *
 *   __construct → _initState → _loadInit → _loadedState → boot
 *   → middleware:before → beforeAction → action → afterAction
 *   → middleware:after → _endState
 *
 * boot() is method-injected (Request + container service, no route params);
 * middleware() wraps ONLY the action phase (beforeAction/action/afterAction).
 */
class HookOrderController
{
    public function __construct()
    {
        Recorder::add('__construct');
    }

    public function _initState(Request $request): void
    {
        Recorder::add('_initState');
    }

    public function _loadInit(Request $request): void
    {
        Recorder::add('_loadInit');
    }

    public function _loadedState(Request $request): void
    {
        Recorder::add('_loadedState');
    }

    public function boot(Request $request, StampService $service): void
    {
        Recorder::add('boot:' . $service->stamp());
    }

    /** @return list<string> */
    public function middleware(): array
    {
        return [RecordingOrderMiddleware::class];
    }

    public function beforeAction(Request $request): ?Response
    {
        Recorder::add('beforeAction');

        if ($request->query->get('deny') === '1') {
            // Short-circuit INSIDE the middleware() wrap: the sub-pipeline
            // still unwinds (middleware:after) around the early response.
            return new Response('denied', 403);
        }

        return null;
    }

    public function show(Request $request): Response
    {
        Recorder::add('action');

        return new Response('hook-order');
    }

    public function afterAction(Request $request, Response $response): ?Response
    {
        Recorder::add('afterAction');
        $response->headers->set('X-After', 'decorated');

        return null;
    }

    public function _endState(Request $request): void
    {
        Recorder::add('_endState');
    }
}
