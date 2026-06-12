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

// Pass the host root explicitly so the app boots under a symlinked
// path-repository install too (local dev / monorepo).
Kernel::boot(dirname(__DIR__));
Kernel::run();
