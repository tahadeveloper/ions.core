<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Target of Route::redirect() shortcut routes (10.7).
 *
 * The destination and status travel as route DEFAULTS (_redirect_to /
 * _redirect_status) — plain scalars, so route:cache can compile the route (a
 * Closure controller could not be dumped). Kernel::handleRouteRequest() copies
 * the matcher params (defaults included) into the request attributes, which is
 * where they are read back — identically on the live and compiled paths.
 *
 * Plain Symfony RedirectResponse (not the flash-capable Ions subclass): a
 * static route-level redirect carries no per-user session state.
 */
final class RedirectController
{
    public function handle(Request $request): SymfonyRedirectResponse
    {
        $to = (string) $request->attributes->get('_redirect_to', '/');
        $status = (int) $request->attributes->get('_redirect_status', 302);

        return new SymfonyRedirectResponse($to, $status);
    }
}
