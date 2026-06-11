<?php

declare(strict_types=1);

namespace IonsFixture\Middleware;

use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use IonsFixture\Lifecycle\Recorder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller-middleware fixture (9.3): records its position in the lifecycle
 * via the shared Recorder and stamps a response header so feature tests can
 * assert per-controller middleware() actually wrapped the action phase.
 */
final class RecordingOrderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        Recorder::add('middleware:before');
        $response = $next($request);
        Recorder::add('middleware:after');
        $response->headers->set('X-Controller-Mw', 'ran');

        return $response;
    }
}
