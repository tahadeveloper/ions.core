<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Bundles\Logs;
use Ions\Container\ServiceProvider;
use Ions\Schedule\Scheduler;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Binds the lazy 'schedule' singleton ({@see Scheduler}).
 *
 * Registration costs nothing on the hot path: the Scheduler — and the host's
 * schedule definitions — are only built the first time something actually
 * resolves the binding (schedule:run, schedule:list, the /cron/schedule web
 * route or the Ions\Support\Schedule facade).
 *
 * Host definition point: config('app.schedule_class') — defaulting to the
 * framework convention 'App\Schedule' (the same class the legacy web-cron
 * route always targeted). When that class declares a boot() accepting a
 * Scheduler (static or instance), it is invoked with the new scheduler on
 * first resolve. A LEGACY zero-parameter boot() is deliberately NOT called:
 * its semantic is "boot() IS the cron job" and it stays wired to the
 * /cron/schedule controller-string dispatch (see WebCronController).
 */
final class ScheduleProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->container->bound('schedule')) {
            return;
        }

        $container = $this->container;
        $container->singleton('schedule', static function () use ($container): Scheduler {
            $cache = null;
            if ($container->bound('cache')) {
                try {
                    /** @var \Illuminate\Cache\CacheManager $manager */
                    $manager = $container->get('cache');
                    $cache = $manager->store();
                } catch (Throwable) {
                    // No usable cache store — overlap locks degrade to plain runs.
                }
            }

            $logger = null;
            try {
                $logger = Logs::create('schedule.log');
            } catch (Throwable) {
                // No writable log path — the scheduler runs unlogged.
            }

            $scheduler = new Scheduler($cache, $logger);
            self::bootHostSchedule($scheduler);

            return $scheduler;
        });

        $container->alias('schedule', Scheduler::class);
    }

    /**
     * The host's schedule definition class (framework convention App\Schedule;
     * config('app.schedule_class') is the test/escape hatch).
     */
    public static function hostScheduleClass(): string
    {
        return (string) config('app.schedule_class', 'App\\Schedule');
    }

    /**
     * True when the class declares a public boot() whose first parameter is
     * type-hinted Scheduler — the new-style definition signature.
     *
     * @param class-string $class
     */
    public static function acceptsScheduler(string $class): bool
    {
        if (!method_exists($class, 'boot')) {
            return false;
        }

        $method = new ReflectionMethod($class, 'boot');
        if (!$method->isPublic() || $method->getNumberOfParameters() < 1) {
            return false;
        }

        $type = $method->getParameters()[0]->getType();

        return $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && is_a($type->getName(), Scheduler::class, true);
    }

    /**
     * Invoke the host's new-style boot(Scheduler) — static or instance —
     * registering its tasks on the given scheduler. Legacy zero-parameter
     * boot() classes (and absent classes) are left untouched.
     */
    public static function bootHostSchedule(Scheduler $scheduler): void
    {
        $class = self::hostScheduleClass();
        if (!class_exists($class) || !self::acceptsScheduler($class)) {
            return;
        }

        $method = new ReflectionMethod($class, 'boot');
        $method->invoke($method->isStatic() ? null : new $class(), $scheduler);
    }
}
