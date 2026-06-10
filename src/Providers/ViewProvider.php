<?php

declare(strict_types=1);

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

        // The shared per-process Twig Environment. Built lazily on first use;
        // dies with the container, so a kernel re-boot yields a fresh one.
        if (!$this->container->bound('view.env')) {
            $this->container->singleton('view.env', function ($app) {
                /** @var ViewFactory $factory */
                $factory = $app->get('view');

                return $factory->build();
            });
        }
    }
}
