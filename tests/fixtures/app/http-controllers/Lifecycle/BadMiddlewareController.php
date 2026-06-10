<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed fixture: middleware() returns an unresolvable alias — the
 * request must 500 (4.1 policy), never serve the action unprotected.
 */
class BadMiddlewareController
{
    /** @return list<string> */
    public function middleware(): array
    {
        return ['NoSuchControllerMiddleware'];
    }

    public function show(Request $request): Response
    {
        return new Response('must-not-run');
    }
}
