<?php

declare(strict_types=1);

namespace IonsFixtureCache\Http;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class PageController
{
    public function show(Request $request): Response
    {
        return new Response('cached page');
    }

    public function item(Request $request): Response
    {
        // Route params are added to the shared kernel request by
        // Kernel::handleRouteRequest() (framework convention).
        return new Response('item ' . Kernel::request()->attributes->get('id'));
    }
}
