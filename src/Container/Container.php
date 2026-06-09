<?php

declare(strict_types=1);

namespace Ions\Container;

use Illuminate\Container\Container as IlluminateContainer;

/**
 * The Ions service container. Extends Illuminate's container (which already
 * implements Psr\Container\ContainerInterface and provides bind/singleton/make)
 * so existing Kernel::app()->make()/singleton() call sites keep working.
 */
class Container extends IlluminateContainer
{
}
