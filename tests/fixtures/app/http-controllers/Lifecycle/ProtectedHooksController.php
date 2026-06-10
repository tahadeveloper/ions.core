<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Lifecycle\Recorder;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hook-name collisions with NON-PUBLIC visibility: a host controller with a
 * protected boot() helper (or a private middleware() helper) must dispatch
 * normally — the new lifecycle hooks are detected on PUBLIC methods only.
 * (Calling these from the dispatcher would throw a visibility Error → 500.)
 */
class ProtectedHooksController
{
    public function show(Request $request): Response
    {
        return new Response('protected-hooks:' . $this->boot());
    }

    protected function boot(): string
    {
        Recorder::add('protected-boot-helper');

        return 'helper';
    }

    /** @return list<string> */
    private function middleware(): array
    {
        // Unresolvable on purpose: if the dispatcher ever read this private
        // helper as the middleware() hook, the request would fail-closed 500.
        return ['NoSuchControllerMiddleware'];
    }
}
