<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Middleware\RecordingOrderMiddleware;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * middleware() returning a BARE MiddlewareInterface instance (not wrapped in
 * an array) — the dispatcher must still run it. A naive `(array)` cast would
 * turn the object into its property map and silently drop it (fail-open).
 */
class BareInstanceMiddlewareController
{
    public function middleware(): MiddlewareInterface
    {
        return new RecordingOrderMiddleware();
    }

    public function show(Request $request): Response
    {
        return new Response('bare-instance');
    }
}
