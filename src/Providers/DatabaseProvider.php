<?php

namespace Ions\Providers;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Ions\Bundles\Path;
use Ions\Container\ServiceProvider;
use Ions\Foundation\Config;
use Ions\Support\DB;
use RedBeanPHP\R;
use RedBeanPHP\RedException;
use Throwable;

/**
 * Wires up database connections (Eloquent / RedBeanPHP) into the container.
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
     * Boot Eloquent and optionally enable query logging; run RedBean setup.
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

            if (config('app.app_debug', false)) {
                DB::connection()->enableQueryLog();
            }
        }

        if (in_array('redbean', $engines, true)) {
            $this->redBeanConnection();
        }
    }

    /**
     * Set up RedBeanPHP connections from database.php config.
     * Guarded so a repeated call does not fatal (R::setup / R::addDatabase
     * are not idempotent, so we check R::testConnection before re-initialising).
     *
     * @throws RedException
     */
    private function redBeanConnection(): void
    {
        // If RedBean is already connected, skip the setup to stay idempotent.
        try {
            if (R::testConnection()) {
                return;
            }
        } catch (Throwable) {
            // R::testConnection may throw if RedBean was never initialised; proceed.
        }

        $databases = new Config(include(Path::config('database.php')));
        $default_database = config('database.default', 'mysql');
        foreach ($databases['connections'] as $key => $connection) {
            if ($key === $default_database) {
                $this->redBeanDriverSetup($connection);
            } else {
                $this->redBeanDriverConnection($connection, $key);
            }
        }

        R::useFeatureSet('novice/latest');
        try {
            R::freeze(!env('APP_DEBUG'));
            R::ext('xdispense', static function ($type) {
                return R::getRedBean()->dispense($type);
            });
        } catch (RedException) {
            die('Can not connect by redbean');
        }
    }

    /**
     * Set up the default RedBean connection for the given driver config.
     *
     * @param mixed $connection
     * @throws RedException
     */
    private function redBeanDriverSetup(mixed $connection): void
    {
        if ($connection['driver'] === 'mysql') {
            R::setup(
                'mysql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'],
                $connection['password']
            );
        } elseif ($connection['driver'] === 'sqlite') {
            R::setup('sqlite:' . $connection['database'], null, null);
        } elseif ($connection['driver'] === 'pgsql') {
            R::setup(
                'pgsql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'],
                $connection['password']
            );
        } else {
            throw new RedException('Invalid database driver');
        }
    }

    /**
     * Register an additional named RedBean connection.
     *
     * @param mixed $connection
     * @param int|string $key
     * @throws RedException
     */
    private function redBeanDriverConnection(mixed $connection, int|string $key): void
    {
        if ($connection['driver'] === 'mysql') {
            R::addDatabase(
                $key,
                'mysql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'],
                $connection['password']
            );
        } elseif ($connection['driver'] === 'sqlite') {
            R::addDatabase($key, 'sqlite:' . $connection['database']);
        } elseif ($connection['driver'] === 'pgsql') {
            R::addDatabase(
                $key,
                'pgsql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'],
                $connection['password']
            );
        } else {
            throw new RedException('Unsupported database driver: ' . $connection['driver']);
        }
    }
}
