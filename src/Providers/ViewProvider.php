<?php

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\View\ViewFactory;

final class ViewProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('view')) {
            $this->container->singleton('view', fn () => new ViewFactory());
        }
    }
}
