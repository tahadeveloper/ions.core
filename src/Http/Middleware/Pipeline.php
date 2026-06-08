<?php

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class Pipeline
{
    /**
     * @param MiddlewareInterface[] $middleware
     * @param callable(Request):Response $terminal
     */
    public function __construct(private array $middleware, private $terminal)
    {
    }

    public function handle(Request $request): Response
    {
        $chain = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, MiddlewareInterface $mw): callable
                => static fn (Request $req): Response => $mw->handle($req, $next),
            $this->terminal,
        );

        return $chain($request);
    }
}
