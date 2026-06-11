<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Config;
use Ions\Foundation\Kernel;
use Ions\Support\DB;
use Throwable;

/**
 * Wires up database connections (Eloquent) into the container.
 */
final class DatabaseProvider extends ServiceProvider
{
    /**
     * Bind db, db.connection, and db.schema when the 'db' engine is enabled
     * and the bindings are not already present (idempotent).
     */
    public function register(): void
    {
        $engines = config('app.database_engine', []);

        if (!in_array('db', $engines, true)) {
            return;
        }

        if ($this->container->bound('db')) {
            return;
        }

        $this->container->singleton('db', function () {
            $capsule = new Manager();
            $databases = new Config(include(Path::config('database.php')));
            $default_database = config('database.default', 'mysql');
            foreach ($databases['connections'] as $key => $connection) {
                ($key !== $default_database) ?: $key = 'default';
                $capsule->addConnection($connection, $key);
            }
            $capsule->setEventDispatcher(new Dispatcher(new IlluminateContainer()));
            $capsule->setAsGlobal();
            return $capsule;
        });

        $this->container->bind('db.connection', function ($app) {
            return $app['db']->connection();
        });

        $this->container->bind('db.schema', function ($app) {
            return $app['db']->connection()->getSchemaBuilder();
        });
    }

    /**
     * Boot Eloquent and optionally enable query logging.
     * A 'redbean' entry in database_engine logs a deprecation warning and is otherwise ignored.
     */
    public function boot(): void
    {
        // ORM strict mode (10.6): debug-only safety nets — lazy relation
        // access throws LazyLoadingViolationException (naming the relation)
        // and fills silently dropped by $fillable throw instead of vanishing.
        // These are Eloquent CLASS statics, so they are set EVERY boot with
        // the freshly computed value (self-correcting across worker re-boots
        // and tests). Production (APP_DEBUG off) is ALWAYS relaxed regardless
        // of config; in debug, 'database.strict' => false is the escape hatch.
        $strict = (bool) env('APP_DEBUG', false) && config('database.strict', true) !== false;
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

        $engines = config('app.database_engine', []);

        if (in_array('db', $engines, true) && $this->container->bound('db')) {
            try {
                $this->container->get('db')->bootEloquent();
            } catch (Throwable) {
                abort(500, 'No database class connect');
            }

            // Pagination (10.3): wire Illuminate's paginator resolvers so
            // $query->paginate($n) works out of the box. The closures read
            // Kernel::request() AT CALL TIME (lazy — correct in worker mode),
            // and fail safe to page 1 / '/' / no query when no request exists
            // (CLI, isolated scripts). Setting class statics is idempotent.
            $this->wirePagination();

            // Query logging is opt-in via config('database.query_log') — the
            // log accumulates every statement in memory for the lifetime of
            // the process, so APP_DEBUG alone no longer enables it (4.1
            // behavior change; see UPGRADE-4.1).
            if (config('database.query_log', false)) {
                DB::connection()->enableQueryLog();
                $this->attachNPlusOneDetector();
            }
        }

        if (in_array('redbean', $engines, true)) {
            Logs::create('database.log')->warning(
                "The 'redbean' database engine was removed in v2; ignoring. Use the 'db' (Eloquent) engine."
            );
        }
    }

    /**
     * Point the paginator's page/path/query-string resolvers at the Ions
     * request. LengthAwarePaginator->withQueryString() (called by the
     * pagination() Twig function) preserves the current query parameters on
     * every page link via the query-string resolver.
     */
    private function wirePagination(): void
    {
        Paginator::currentPathResolver(static function (): string {
            try {
                return Kernel::request()->url();
            } catch (Throwable) {
                return '/';
            }
        });

        Paginator::currentPageResolver(static function (string $pageName = 'page'): int {
            try {
                $page = Kernel::request()->query($pageName);
            } catch (Throwable) {
                return 1;
            }

            return is_numeric($page) && (int) $page >= 1 ? (int) $page : 1;
        });

        Paginator::queryStringResolver(static function (): array {
            try {
                return (array) Kernel::request()->query();
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Debug-only, zero-config N+1 detector: when the query log is on AND
     * APP_DEBUG is truthy, listen for the kernel's RequestHandled event and
     * scan the request's query log for repeated single-row SELECT patterns
     * ({@see \Ions\Database\NPlusOneDetector}). Warnings go to
     * var/logs/performance.log.
     *
     * Escape hatch: 'database.nplusone.enabled' => false. Threshold:
     * 'database.nplusone.threshold' (default 5). Production (APP_DEBUG off or
     * query_log off) attaches nothing — zero hot-path cost.
     *
     * Safe ordering: providers register() in one pass before any boot(), so
     * EventProvider::register() has already bound 'events' by the time this
     * boot() runs (and we guard anyway for hosts with a custom provider list).
     * Idempotent via a container marker — boot() may run more than once
     * against the same container (worker re-boots, tests).
     */
    private function attachNPlusOneDetector(): void
    {
        if (!env('APP_DEBUG', false)
            || !config('database.nplusone.enabled', true)
            || !$this->container->bound('events')
            || $this->container->bound('db.nplusone.attached')
        ) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->container->get('events');
        $events->listen(\Ions\Events\RequestHandled::class, \Ions\Database\Listeners\DetectNPlusOne::class);
        $this->container->instance('db.nplusone.attached', true);
    }
}
