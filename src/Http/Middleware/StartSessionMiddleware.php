<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Session\SessionManager;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the framework session at the front of the web pipeline, attaches the
 * underlying Symfony session to the Request (so downstream code — including the
 * CSRF storage — can reach it), and persists it on the way out.
 *
 * Stateless (api) requests do NOT use this middleware.
 */
final class StartSessionMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionManager $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start();
        $request->setSession($this->session->getSession());

        $response = $next($request);

        $this->session->save();

        return $response;
    }
}
