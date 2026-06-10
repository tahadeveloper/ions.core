<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Lifecycle\Recorder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression fixture: a route whose ACTION method is literally named boot().
 * The dispatcher must run it exactly once — as the action — and must NOT also
 * fire it as the 9.3 boot() lifecycle hook (the pre-4.2 web-cron contract:
 * legacy App\Schedule::boot ran once per hit).
 */
class BootActionController
{
    public function boot(): Response
    {
        Recorder::add('boot-action');

        return new Response('boot-action');
    }
}
