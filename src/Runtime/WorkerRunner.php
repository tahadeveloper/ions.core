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
 * See docs/worker-mode.md for usage and a FrankenPHP adapter example.
 *
 * @experimental Worker mode is experimental in 4.1: the reset lifecycle covers
 *               framework-owned state only — host applications must avoid
 *               their own mutable static/per-process state (see the docs for
 *               known limitations, e.g. native PHP sessions in workers).
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
