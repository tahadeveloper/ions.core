<?php

namespace Ions\Foundation;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Ions\Bundles\Path;
use Ions\Support\DB;
use RedBeanPHP\R;
use RedBeanPHP\RedException;
use Throwable;

class RegisterDB extends Singleton
{

    public static function boot(): void
    {
        $allow_database_engine = config('app.database_engine', []);

        if (in_array('db', $allow_database_engine, true)) {
            self::DBConnections();
        }

        if (in_array('redbean', $allow_database_engine, true)) {
            self::redBeanConnection();
        }
    }

    protected static function DBConnections(): void
    {
        if (!Kernel::app()->has('db')) {

            Kernel::app()->singleton('db', function () {
                $capsule = new Manager;
                $databases = new Config(include(Path::config('database.php')));
                $default_database = config('database.default', 'mysql');
                foreach ($databases['connections'] as $key => $connection) {
                    ($key !== $default_database) ?: $key = 'default';
                    $capsule->addConnection($connection, $key);
                }
                $capsule->setEventDispatcher(new Dispatcher(new Container()));
                $capsule->setAsGlobal();
                return $capsule;
            });

            Kernel::app()->bind('db.connection', function ($app) {
                return $app['db']->connection();
            });

            Kernel::app()->bind('db.schema', function ($app) {
                return $app['db']->connection()->getSchemaBuilder();
            });

            try {
                Kernel::app()->get('db')->bootEloquent();
            } catch (Throwable) {
                abort(500, 'No database class connect');
            }

            if (config('app.app_debug', false)) {
                DB::connection()->enableQueryLog();
            }
        }
    }

    /**
     * @throws RedException
     */
    protected static function redBeanConnection(): void
    {
        $databases = new Config(include(Path::config('database.php')));
        $default_database = config('database.default', 'mysql');
        foreach ($databases['connections'] as $key => $connection) {
            if ($key === $default_database) {
                self::RedBeanDriverSetup($connection);
            } else {
                self::RedBeanDriverConnection($connection, $key);
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
     * @param mixed $connection
     * @return void
     * @throws RedException
     */
    protected static function RedBeanDriverSetup(mixed $connection): void
    {
        if ($connection['driver'] === 'mysql') {
            R::setup('mysql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'], $connection['password']); //for both mysql or mariaDB
        } elseif ($connection['driver'] === 'sqlite') {
            R::setup('sqlite:'.$connection['database'], null, null);
        } elseif ($connection['driver'] === 'pgsql') {
            R::setup('pgsql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'], $connection['password']);
        } else {
            throw new RedException('Invalid database driver');
        }
    }

    /**
     * @param mixed $connection
     * @param int|string $key
     * @return void
     * @throws RedException
     */
    protected static function RedBeanDriverConnection(mixed $connection, int|string $key): void
    {
        if ($connection['driver'] === 'mysql') {
            R::addDatabase($key, 'mysql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'], $connection['password']);
        } elseif ($connection['driver'] === 'sqlite') {
            R::addDatabase($key, 'sqlite:'.$connection['database']);
        } elseif ($connection['driver'] === 'pgsql') {
            R::addDatabase($key, 'pgsql:host=' . $connection['host'] . ';dbname=' . $connection['database'],
                $connection['username'], $connection['password']);
        } else {
            throw new RedException('Unsupported database driver: ' . $connection['driver']);
        }
    }
}
