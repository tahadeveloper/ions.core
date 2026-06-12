<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front controller
|--------------------------------------------------------------------------
| Every HTTP request enters here. Kernel::boot() loads .env, builds the
| container, reads config/, registers the service providers and loads the
| routes; Kernel::run() captures the request, sends it through the
| middleware pipeline and emits the response.
|
| Serve locally with:  php bin/ions serve   (or: php -S localhost:8000 -t public)
*/

require __DIR__ . '/../vendor/autoload.php';

use Ions\Foundation\Kernel;

// Pass the host root explicitly (this file lives at <root>/public/index.php).
// Without it, Kernel resolves the root five directories up from the core
// package — correct for a normal vendor/ install, but wrong when the core is
// installed via a symlinked path repository (local dev / monorepo), where
// __DIR__ resolves the symlink to the core's real location.
Kernel::boot(dirname(__DIR__));
Kernel::run();
