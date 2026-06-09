<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

interface MiddlewareInterface
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
