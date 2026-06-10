<?php

declare(strict_types=1);

namespace Ions\Database\Listeners;

use Illuminate\Database\Capsule\Manager;
use Ions\Bundles\Logs;
use Ions\Database\NPlusOneDetector;
use Ions\Events\RequestHandled;
use Ions\Foundation\Kernel;
use Throwable;

/**
 * Debug-only RequestHandled listener that runs the {@see NPlusOneDetector}
 * over the request's query log and writes ONE warning per offending pattern
 * to var/logs/performance.log (pattern, count, total ms, request path).
 *
 * Auto-attached by DatabaseProvider::boot() when APP_DEBUG is truthy AND
 * config('database.query_log') is on AND config('database.nplusone.enabled')
 * is not false — production gets zero wiring and zero hot-path cost. The
 * guards are re-checked here so a manually registered listener (events.listen)
 * behaves identically.
 *
 * Diagnostics must never break a response: the whole body is wrapped in a
 * catch-all and failures are swallowed.
 */
final class DetectNPlusOne
{
    public function handle(RequestHandled $event): void
    {
        try {
            if (!env('APP_DEBUG', false)
                || !config('database.query_log', false)
                || !config('database.nplusone.enabled', true)
                || !Kernel::app()->bound('db')
            ) {
                return;
            }

            /** @var Manager $capsule */
            $capsule = Kernel::app()->get('db');
            $queryLog = $capsule->getConnection()->getQueryLog();

            $threshold = (int) config('database.nplusone.threshold', NPlusOneDetector::DEFAULT_THRESHOLD);
            $offenders = NPlusOneDetector::analyze($queryLog, $threshold);
            if ($offenders === []) {
                return;
            }

            $path = $event->request->getPathInfo();
            $logger = Logs::create('performance.log');
            foreach ($offenders as $offender) {
                $logger->warning(
                    sprintf(
                        'Possible N+1 query: pattern executed %d times (%.2f ms total) during %s — fix with eager loading (->with(...)) or one WHERE ... IN (...) query. Pattern: %s',
                        $offender['count'],
                        $offender['total_time'],
                        $path,
                        $offender['pattern'],
                    ),
                    [
                        'pattern' => $offender['pattern'],
                        'count' => $offender['count'],
                        'total_time_ms' => $offender['total_time'],
                        'path' => $path,
                    ]
                );
            }
        } catch (Throwable) {
            // Intentionally ignored: diagnostics must never break the response.
        }
    }
}
