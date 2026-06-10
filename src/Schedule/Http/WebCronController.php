<?php

declare(strict_types=1);

namespace Ions\Schedule\Http;

use DateTimeImmutable;
use Ions\Console\Kernel as ConsoleKernel;
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Providers\ScheduleProvider;
use Ions\Schedule\Scheduler;
use Ions\Support\JsonResponse;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Target of the built-in /cron/schedule route (web-cron fallback for hosts
 * without system cron access). The host signature decides the behavior — at
 * HIT time, so the route itself stays a cacheable controller string and adds
 * zero boot cost:
 *
 *  - new-style boot(Scheduler): resolve the shared 'schedule' scheduler
 *    (ScheduleProvider registers the host tasks on first resolve) and run the
 *    due tasks — exactly what schedule:run does — answering with a JSON
 *    summary {ran, failed, skipped};
 *  - legacy zero-parameter boot(): dispatch the class through the SAME
 *    controller-string pipeline the route always used ('App\Schedule::boot'
 *    via ControllerDispatcher) — byte-for-byte BC for non-adopting hosts;
 *  - no schedule class at all: 404.
 */
final class WebCronController
{
    public function run(Request $request): Response
    {
        $class = ScheduleProvider::hostScheduleClass();

        if (!class_exists($class)) {
            abort(404, 'No schedule class is defined.');
        }

        if (!ScheduleProvider::acceptsScheduler($class)) {
            // Legacy convention: boot() IS the cron job — keep the exact
            // controller-string dispatch (lifecycle hooks, normalization).
            return (new ControllerDispatcher(Kernel::app(), $class, 'boot', []))($request);
        }

        /** @var Scheduler $scheduler */
        $scheduler = Kernel::app()->get('schedule');

        $summary = $scheduler->runDue(new DateTimeImmutable(), self::commandRunner());

        return new JsonResponse($summary);
    }

    /**
     * Command-task runner for the web context: a console application is built
     * against the ALREADY-booted kernel (never re-booting it mid-request),
     * lazily — only when the first due command task actually needs it.
     *
     * @return callable(string, array<int|string, mixed>): int
     */
    private static function commandRunner(): callable
    {
        $application = null;

        return static function (string $signature, array $arguments) use (&$application): int {
            $application ??= ConsoleKernel::forBootedKernel()->getApplication();

            return (int) $application->call($signature, $arguments);
        };
    }
}
