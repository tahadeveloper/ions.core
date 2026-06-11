<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Support\Request;
use Ions\View\View;

/**
 * Target of Route::view() shortcut routes (10.7).
 *
 * The template name and data travel as route DEFAULTS (_view_template /
 * _view_data) — plain scalars/arrays, so route:cache can compile the route.
 * Kernel::handleRouteRequest() copies the matcher params into the request
 * attributes; the template renders lazily through the 9.2 View (the
 * ControllerDispatcher's ResponseNormalizer calls render()).
 */
final class ViewController
{
    public function handle(Request $request): View
    {
        /** @var array<string, mixed> $data */
        $data = (array) $request->attributes->get('_view_data', []);

        return view((string) $request->attributes->get('_view_template'), $data);
    }
}
