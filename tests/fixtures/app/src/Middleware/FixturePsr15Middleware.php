<?php

declare(strict_types=1);

namespace IonsFixture\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Real PSR-15 middleware fixture (10.8): mutates the request (attribute) AND
 * the response (header) so the Psr15Adapter tests can assert both directions
 * of the Symfony <-> PSR-7 conversion propagate through the Ions pipeline.
 */
final class FixturePsr15Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request->withAttribute('psr15_note', 'seen-by-psr15'));

        return $response->withHeader('X-Psr15', 'applied');
    }
}
