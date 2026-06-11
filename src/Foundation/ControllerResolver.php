<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Support\Arr;
use Ions\Support\Str;

/**
 * Resolves a matched route's `_controller` string into a [controller, method]
 * pair and applies framework namespacing. Extracted verbatim from
 * Kernel::handleRouteRequest() (12.7) — pure move, no behavior change.
 *
 * This collaborator is intentionally side-effect free: the config('app._method')
 * write and the request-attribute additions stay in Kernel so the per-request
 * mutation surface is unchanged.
 *
 * @internal Collaborator of {@see Kernel}; not part of the public API.
 */
final class ControllerResolver
{
    /**
     * @param array<string, mixed> $matcherParams Route matcher params (must carry '_controller').
     * @param string               $namespace     Controller namespace prefix (may be '').
     * @param list<string>         $needles       Substrings that select the bare-namespace branch
     *                                            (super/api/Api + app.needles).
     * @return array{0: string, 1: string, 2: array<string, mixed>} [controller, method, filtered params]
     */
    public static function resolve(array $matcherParams, string $namespace, array $needles): array
    {
        // check if using :: or @ for method
        if (str_contains($matcherParams['_controller'], '::')) {
            // action -> as text : NameController::action
            $exControllerMethod = explode('::', $matcherParams['_controller']);
        } elseif (str_contains($matcherParams['_controller'], '@')) {
            // action -> as text : NameController@action
            $exControllerMethod = explode('@', $matcherParams['_controller']);
        }

        $controller = $exControllerMethod[0] ?? $matcherParams['_controller'];
        $method = $exControllerMethod[1] ?? $matcherParams['method'];

        // remove id from parameters when 0 value
        $matcherParams = Arr::where($matcherParams, static function ($value, $key) {
            return !($key === 'id' && $value === 0);
        });

        // add namespace to controller if didn't have — framework-shipped
        // controllers (Ions\…, e.g. the web-cron controller) and the legacy
        // 'App\Schedule' special case are absolute and never prefixed.
        if ($namespace && $controller !== 'App\Schedule' && !str_starts_with($controller, 'Ions\\') && !Str::contains($controller, $namespace)) {
            // check if super or api
            if (Str::contains($controller, $needles, true) || Str::contains($namespace, 'Api')) {
                $controller = $namespace . $controller;
            } else {
                $controller = $namespace . 'Controllers\\' . $controller;
            }
        }

        return [$controller, $method, $matcherParams];
    }
}
