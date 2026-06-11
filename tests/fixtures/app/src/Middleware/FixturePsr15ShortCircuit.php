<?php

declare(strict_types=1);

namespace IonsFixture\Middleware;

use Nyholm\Psr7\Response as PsrResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 short-circuit fixture (10.8): returns its own response WITHOUT
 * calling the handler — the adapter must surface it and the terminal
 * (controller) must never run.
 */
final class FixturePsr15ShortCircuit implements MiddlewareInterface
{
    /** Set by the guarded route's closure — must stay false when short-circuited. */
    public static bool $terminalRan = false;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new PsrResponse(418, ['X-Short-Circuit' => 'yes'], 'short-circuited');
    }
}
