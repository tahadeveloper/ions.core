<?php

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Foundation\Kernel;

final class ConfigProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('config')) {
            $this->container->instance('config', Kernel::config());
        }
    }
}
