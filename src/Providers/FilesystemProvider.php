<?php

declare(strict_types=1);

namespace Ions\Providers;

use Illuminate\Filesystem\Filesystem;
use Ions\Container\ServiceProvider;
use Ions\Filesystem\FilesystemManager;

final class FilesystemProvider extends ServiceProvider
{
    public function register(): void
    {
        // BC: the low-level Illuminate filesystem used by Storage::files() during
        // config capture and by host apps. Must stay an Illuminate\Filesystem.
        if (!$this->container->bound('filesystem')) {
            $this->container->singleton('filesystem', fn () => new Filesystem());
        }
        if (!$this->container->bound('files')) {
            $this->container->singleton('files', fn () => new Filesystem());
        }

        // First-class, config-driven Flysystem driver manager.
        if (!$this->container->bound('filesystem.manager')) {
            $this->container->singleton('filesystem.manager', fn () => new FilesystemManager());
        }

        // The default disk resolved from config('filesystem.default').
        if (!$this->container->bound('filesystem.disk')) {
            $this->container->bind(
                'filesystem.disk',
                fn ($app) => $app->get('filesystem.manager')->disk()
            );
        }
    }
}
