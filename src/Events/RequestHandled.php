<?php

declare(strict_types=1);

namespace Ions\Events;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fired once per request, after the middleware pipeline produces a response and
 * before it is sent. Carries the request and the resolved response so listeners
 * can observe (logging, metrics, header tweaks) without altering control flow.
 *
 * Dispatched from {@see \Ions\Foundation\Kernel::handle()} in a fire-and-continue
 * manner: listener failures are swallowed so they never break the response.
 */
final class RequestHandled
{
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
    ) {
    }
}
