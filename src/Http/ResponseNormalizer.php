<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Ions\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single normalizer for controller-action AND closure-route return values
 * (9.3 unification — previously ControllerDispatcher::normalize() and
 * Kernel::normalizeToResponse() drifted: closures could not return
 * Responsable).
 *
 * Rules, in order:
 *   - Response     → returned as-is
 *   - Responsable  → toResponse($request)
 *   - Ions View    → 200 HTML Response via viewResponse() (9.2 bridge)
 *   - anything else (null/void/arrays/strings) → the shared kernel Response,
 *     which the action may have written to via Kernel::response() (legacy BC)
 */
final class ResponseNormalizer
{
    public static function normalize(mixed $result, Request $request): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result instanceof Responsable) {
            return $result->toResponse($request);
        }

        if ($result instanceof View) {
            return self::viewResponse($result);
        }

        return Kernel::response();
    }

    /**
     * Convert an Ions\View\View return into a 200 HTML Response.
     *
     * The Content-Type is set explicitly (rather than relying on Symfony's
     * prepare()-time default) so tests and middleware see it immediately.
     */
    public static function viewResponse(View $view): Response
    {
        $response = new Response($view->render(), Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
