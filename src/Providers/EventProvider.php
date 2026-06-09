<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Ions\Container\ServiceProvider;

/**
 * Binds the shared Illuminate event {@see Dispatcher} into the container as
 * 'events' and auto-registers listeners declared in config('events.listen').
 *
 * The listen map mirrors Laravel's EventServiceProvider shape:
 *   'listen' => [
 *       SomeEvent::class => [SomeListener::class, AnotherListener::class],
 *   ]
 * String listeners are resolved through the container (so they may use
 * constructor injection); their handle() method receives the event object.
 */
final class EventProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('events')) {
            $container = $this->container;
            $this->container->singleton('events', static fn (): Dispatcher => new Dispatcher($container));
        }

        // Alias the contracts so libraries that type-hint the interface (e.g. the
        // queue's CallQueuedHandler) resolve the same shared dispatcher.
        if (!$this->container->bound(DispatcherContract::class)) {
            $this->container->alias('events', DispatcherContract::class);
        }
        if (!$this->container->bound(Dispatcher::class)) {
            $this->container->alias('events', Dispatcher::class);
        }
    }

    public function boot(): void
    {
        /** @var Dispatcher $events */
        $events = $this->container->get('events');

        /** @var array<string, mixed> $listen */
        $listen = (array) config('events.listen', []);

        foreach ($listen as $event => $listeners) {
            if (!is_string($event)) {
                continue;
            }
            foreach ((array) $listeners as $listener) {
                // Listeners are class-strings (resolved by the dispatcher through
                // the container) or callables. Anything else is skipped.
                if (is_string($listener) && class_exists($listener)) {
                    $events->listen($event, $listener);
                } elseif (is_callable($listener)) {
                    $events->listen($event, $listener);
                }
            }
        }
    }
}
