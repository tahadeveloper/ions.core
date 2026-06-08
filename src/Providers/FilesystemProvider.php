<?php

namespace Ions\Providers;

use Illuminate\Filesystem\Filesystem;
use Ions\Container\ServiceProvider;

final class FilesystemProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('filesystem')) {
            $this->container->singleton('filesystem', fn () => new Filesystem());
        }
        if (!$this->container->bound('files')) {
            $this->container->singleton('files', fn () => new Filesystem());
        }
    }
}
