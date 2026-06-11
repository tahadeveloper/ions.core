<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Foundation\Kernel;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Shared named-route URL resolution (Phase 10.3).
 *
 * Extracted from the signedRoute() helper so route(), signedRoute() and
 * Redirector::route() generate byte-identical URLs from one code path
 * (10.7's routing work builds on this).
 *
 * Name lookup precedence (mirrors signedRoute()): the shared collection
 * (Kernel::RouteCollection() — App\Booting/tests) → 'web' group → 'api'
 * group; first hit wins.
 *
 * URL generation deliberately uses a bare RequestContext (not one derived
 * from the current request): config('app.app_url') already contains any
 * APP_FOLDER subfolder, and a request-derived context would contribute its
 * baseUrl too, doubling the folder on subfolder deployments.
 *
 * @internal Use the route()/signedRoute() helpers or redirect()->route().
 */
final class UrlResolver
{
    /**
     * Absolute URL for a named route. $params fill route placeholders;
     * leftovers become query parameters.
     *
     * @param array<string, mixed> $params
     *
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException when the name is unknown.
     */
    public static function route(string $name, array $params = []): string
    {
        // The shared collection only holds routes registered outside route
        // files (App\Booting, tests). Routes from routes/{web|api}.php and
        // attributes live in the per-group captures — search those next.
        $routes = Kernel::RouteCollection();
        if ($routes->get($name) === null) {
            foreach (['web', 'api'] as $group) {
                $candidate = Kernel::routesFor($group);
                if ($candidate->get($name) !== null) {
                    $routes = $candidate;
                    break;
                }
            }
        }

        $generator = new UrlGenerator($routes, new RequestContext());
        $path = $generator->generate($name, $params, UrlGeneratorInterface::ABSOLUTE_PATH);

        $base = rtrim((string) config('app.app_url', ''), '/');

        return $base . $path;
    }
}
