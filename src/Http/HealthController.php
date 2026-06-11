<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Foundation\Doctor;
use Ions\Support\JsonResponse;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Target of the built-in /up route (Phase 10.6) — registered by
 * Kernel::buildRouteCollection() unless config('app.health.enabled') is
 * false, using the same controller-string pattern as /cron/schedule
 * ('Ions\'-prefixed controllers are never namespace-prefixed).
 *
 *  - GET /up
 *      Plain 200 'ok' liveness probe for load balancers and uptime
 *      monitors. No session is touched and nothing is rendered; the route
 *      does go through the normal web middleware stack (security headers,
 *      CORS, session start when bound) — acceptable, since a probe that
 *      exercises the real pipeline is a more honest signal than one that
 *      bypasses it.
 *
 *  - GET /up?checks=1&token=...
 *      Full diagnostics: runs {@see Doctor} and answers with the SAME JSON
 *      shape as `ions doctor --json` ({checks, summary, ok}). Always 200 —
 *      a failing check is reported in the body (ok: false), not the status,
 *      so monitors can distinguish "app down" (non-200) from "app up but
 *      misconfigured" (200 + ok: false). Doctor output names paths and
 *      config state, so checks are token-gated: config('app.health.token')
 *      must be set AND match ?token= or the request is rejected with 403.
 *
 * Responses are marked Cache-Control: no-store — Kernel::handle() leaves
 * no-store responses out of its public/max-age web caching defaults, so a
 * CDN can never mask an outage with a cached 'ok'.
 */
final class HealthController
{
    public function up(Request $request): Response
    {
        if (!$request->query->has('checks')) {
            return new Response('ok', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $token = config('app.health.token');
        $provided = $request->query('token', '');
        $provided = is_string($provided) ? $provided : '';

        // Token unset or mismatched -> 403. hash_equals keeps the compare
        // constant-time; an empty configured token NEVER opens the gate.
        if (!is_string($token) || $token === '' || !hash_equals($token, $provided)) {
            abort(403, 'Health checks require a valid token.');
        }

        $results = Doctor::run();

        $response = new JsonResponse([
            'checks' => $results,
            'summary' => Doctor::summary($results),
            'ok' => !Doctor::hasFailures($results),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
