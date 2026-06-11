<?php

declare(strict_types=1);

namespace Ions\Runtime;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic worker-mode request loop: boot once, handle many requests.
 *
 * The runner is runtime-agnostic — it pulls requests from a provider callable
 * and pushes responses to an emitter callable, so the same loop is testable
 * without any worker SAPI and adaptable to FrankenPHP, RoadRunner or Swoole
 * (each adapter only has to translate its native request/response objects).
 * Between iterations Kernel::resetForRequest() clears all per-request state
 * (request/response statics, session, per-request Twig globals, query log)
 * while keeping boot state (config, container singletons, route memo).
 *
 * See docs/worker-mode.md for usage and FrankenPHP/RoadRunner recipes.
 *
 * Worker mode is stable as of 4.5 (Phase 12.6): the per-request reset is
 * proven to isolate every framework subsystem added through 8.x–12.x by the
 * multi-subsystem leak matrix (tests/Feature/Runtime/WorkerLeakMatrixTest.php
 * — Gate user, flash, session/CSRF, trusted proxies, query log, response
 * cache, IonDisk overrides). The reset lifecycle still covers framework-owned
 * state ONLY — host applications must avoid their own mutable static or
 * per-process state (see docs/worker-mode.md "Host responsibilities").
 */
final class WorkerRunner
{
    /**
     * @param string      $namespace Controller namespace prefix forwarded to Kernel::handle().
     * @param string|null $basePath  Host-app root used when the runner has to boot the
     *                               kernel itself (null = the default 5-levels-up resolution).
     */
    public function __construct(
        private readonly string $namespace = '',
        private readonly ?string $basePath = null,
    ) {
    }

    /**
     * Run the worker loop until the provider returns null or $maxRequests is reached.
     *
     * @param callable(): (Request|null)        $requestProvider Returns the next request, or null to stop.
     * @param callable(Response, Request): void $responseEmitter Emits a handled response to the runtime.
     * @param int|null                          $maxRequests     Optional recycle limit (null = unlimited).
     * @return int Number of requests handled.
     */
    public function run(callable $requestProvider, callable $responseEmitter, ?int $maxRequests = null): int
    {
        if (!Kernel::isBooted()) {
            Kernel::boot($this->basePath);
        }

        $handled = 0;

        while ($maxRequests === null || $handled < $maxRequests) {
            $request = $requestProvider();
            if ($request === null) {
                break;
            }

            Kernel::resetForRequest();
            $response = Kernel::handle($request, $this->namespace);
            $responseEmitter($response, $request);

            $handled++;
        }

        return $handled;
    }
}
