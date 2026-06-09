<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\Connectors\NullConnector;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\QueueManager;
use Ions\Container\ServiceProvider;
use Ions\Queue\ConsoleExceptionHandler;

/**
 * Binds the shared Illuminate {@see QueueManager} into the container as 'queue'
 * and registers the connectors the framework supports: sync (default),
 * database and redis.
 *
 * The manager consumes its $app via array-access for 'config', 'events',
 * 'cache' and 'db' — all already resolvable from the Ions container — so the
 * container is handed straight to the manager and its connectors.
 *
 * Connections are configured under config('queue.*'):
 *   'default'     => 'sync'
 *   'connections' => [ 'sync' => ['driver' => 'sync'], 'database' => [...], ... ]
 */
final class QueueProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->container->bound('queue')) {
            return;
        }

        $this->ensureDefaults();

        $container = $this->container;
        $this->container->singleton('queue', static function () use ($container): QueueManager {
            // QueueManager only consumes its $app via array-access for the
            // 'config', 'events', 'cache' and 'db' bindings — all resolvable
            // from the Ions (Illuminate) container.
            /** @phpstan-ignore argument.type */
            $manager = new QueueManager($container);

            $manager->addConnector('sync', static fn (): SyncConnector => new SyncConnector());
            $manager->addConnector('null', static fn (): NullConnector => new NullConnector());
            // The DatabaseConnector needs a ConnectionResolverInterface; the
            // 'db' binding is the Eloquent Capsule whose DatabaseManager is one.
            $manager->addConnector('database', static function () use ($container): DatabaseConnector {
                /** @var \Illuminate\Database\Capsule\Manager $capsule */
                $capsule = $container->get('db');

                return new DatabaseConnector($capsule->getDatabaseManager());
            });

            // Redis: only wired when the host binds a Redis Factory as 'redis'
            // (the framework ships no Redis manager of its own).
            $redis = $container->bound('redis') ? $container->get('redis') : null;
            if ($redis instanceof RedisFactory) {
                $manager->addConnector('redis', static fn (): RedisConnector => new RedisConnector($redis));
            }

            return $manager;
        });

        // The default connection instance (the 'queue.connection' alias mirrors
        // Laravel's binding so a Job can resolve its target queue).
        $this->container->bind('queue.connection', static fn () => $container->get('queue')->connection());

        // The queue Worker reports failures through an ExceptionHandler; bind a
        // console-friendly one unless the host already provided its own.
        if (!$this->container->bound(ExceptionHandler::class)) {
            $this->container->singleton(ExceptionHandler::class, static fn (): ConsoleExceptionHandler => new ConsoleExceptionHandler());
        }

        // The queue's CallQueuedHandler injects a bus Dispatcher when it runs a
        // job; bind one backed by the shared queue manager.
        if (!$this->container->bound(BusDispatcherContract::class)) {
            $this->container->singleton(BusDispatcherContract::class, static function () use ($container): BusDispatcher {
                return new BusDispatcher(
                    $container,
                    static fn (?string $connection = null) => $container->get('queue')->connection($connection),
                );
            });
        }
    }

    /**
     * Populate missing queue config keys with framework defaults.
     */
    private function ensureDefaults(): void
    {
        if (config('queue.default') === null) {
            config(['queue.default' => 'sync']);
        }

        /** @var array<string,mixed> $connections */
        $connections = (array) config('queue.connections', []);

        if (!isset($connections['sync'])) {
            config(['queue.connections.sync' => ['driver' => 'sync']]);
        }

        if (!isset($connections['database'])) {
            config(['queue.connections.database' => [
                'driver'      => 'database',
                'table'       => 'jobs',
                'queue'       => 'default',
                'retry_after' => 90,
            ]]);
        }
    }
}
