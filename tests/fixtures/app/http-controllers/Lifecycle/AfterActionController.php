<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * afterAction() fixture: receives the NORMALIZED response (the action returns
 * a string-bodied View-free Response here). ?replace=1 returns a replacement
 * Response (non-null return wins); otherwise it decorates and returns null.
 */
class AfterActionController
{
    public function show(Request $request): Response
    {
        return new Response('original', 200);
    }

    public function afterAction(Request $request, Response $response): ?Response
    {
        if ($request->query->get('replace') === '1') {
            return new Response('replaced', 201);
        }

        $response->headers->set('X-Decorated', 'yes');

        return null;
    }
}
