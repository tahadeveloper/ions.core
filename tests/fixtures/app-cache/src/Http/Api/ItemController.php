<?php

declare(strict_types=1);

namespace IonsFixtureCache\Http\Api;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class ItemController
{
    public function show(Request $request): Response
    {
        return new Response('api cached');
    }

    public function secret(Request $request): Response
    {
        return new Response('secret data');
    }

    public function throttled(Request $request): Response
    {
        return new Response('throttled ok');
    }
}
