<?php

declare(strict_types=1);

namespace Ions\Console;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
use Ions\Container\Container as IonsContainer;

/**
 * Thin container proxy used by the console Kernel.
 *
 * Illuminate\Console\Command and Illuminate\Console\Application expect an
 * Illuminate container that also answers runningUnitTests(). The booted Ions
 * container provides every framework binding (config, db, session, filesystem,
 * …) but, like a plain Illuminate\Container\Container, has no runningUnitTests()
 * method. This proxy forwards all resolution to the inner Ions container while
 * adding the handful of methods the console layer needs.
 */
class ConsoleContainerProxy extends IlluminateContainer
{
    private IonsContainer $inner;

    public function __construct(IonsContainer $inner)
    {
        $this->inner = $inner;
    }

    /**
     * The console layer (ConfirmableTrait) calls this; outside an interactive
     * shell we are effectively non-interactive, so report true.
     */
    public function runningUnitTests(): bool
    {
        return true;
    }

    /**
     * @param string|class-string $abstract
     * @param array<string, mixed> $parameters
     */
    public function make($abstract, array $parameters = []): mixed
    {
        return $this->inner->make($abstract, $parameters);
    }

    /**
     * @param string|class-string $abstract
     * @param array<string, mixed> $parameters
     */
    public function makeWith($abstract, array $parameters = []): mixed
    {
        return $this->inner->make($abstract, $parameters);
    }

    /**
     * @param string $id
     */
    public function get($id): mixed
    {
        return $this->inner->get($id);
    }

    /**
     * @param string $abstract
     */
    public function bound($abstract): bool
    {
        return $this->inner->bound($abstract);
    }

    /**
     * @param string $id
     */
    public function has($id): bool
    {
        return $this->inner->has($id);
    }

    /**
     * @param string $abstract
     */
    public function resolved($abstract): bool
    {
        return $this->inner->resolved($abstract);
    }

    /**
     * @param string $abstract
     * @param Closure|string|null $concrete
     */
    public function bind($abstract, $concrete = null, $shared = false): void
    {
        $this->inner->bind($abstract, $concrete, $shared);
    }

    /**
     * @param string $abstract
     * @param Closure|string|null $concrete
     */
    public function singleton($abstract, $concrete = null): void
    {
        $this->inner->singleton($abstract, $concrete);
    }

    /**
     * @param string $abstract
     * @param mixed $instance
     */
    public function instance($abstract, $instance): mixed
    {
        return $this->inner->instance($abstract, $instance);
    }

    /**
     * @param callable|string $callback
     * @param array<string, mixed> $parameters
     */
    public function call($callback, array $parameters = [], $defaultMethod = null): mixed
    {
        return $this->inner->call($callback, $parameters, $defaultMethod);
    }
}
