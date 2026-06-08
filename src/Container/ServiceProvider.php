<?php

namespace Ions\Container;

abstract class ServiceProvider
{
    public function __construct(protected Container $container)
    {
    }

    /** Bind services into the container. Runs for ALL providers before any boot(). */
    abstract public function register(): void;

    /** Side-effecting startup that may depend on other providers' bindings. */
    public function boot(): void
    {
    }
}
