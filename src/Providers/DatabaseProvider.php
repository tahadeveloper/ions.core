<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Config;
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
        $engines = config('app.database_engine', []);

        if (in_array('db', $engines, true) && $this->container->bound('db')) {
            try {
                $this->container->get('db')->bootEloquent();
            } catch (Throwable) {
                abort(500, 'No database class connect');
            }

            if (env('APP_DEBUG', false)) {
                DB::connection()->enableQueryLog();
            }
        }

        if (in_array('redbean', $engines, true)) {
            Logs::create('database.log')->warning(
                "The 'redbean' database engine was removed in v2; ignoring. Use the 'db' (Eloquent) engine."
            );
        }
    }
}
