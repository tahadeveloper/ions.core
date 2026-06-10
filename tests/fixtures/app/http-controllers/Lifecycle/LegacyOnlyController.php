<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use IonsFixture\Lifecycle\Recorder;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-9.3 controller shape: ONLY legacy underscore hooks + an UNTYPED action
 * parameter (legacy position-0 contract — it must still receive the Request).
 * Pins that controllers not using the new features behave byte-identically.
 */
class LegacyOnlyController
{
    public function _initState(Request $request): void
    {
        Recorder::add('_initState');
    }

    public function _loadInit(Request $request): void
    {
        Recorder::add('_loadInit');
    }

    public function _loadedState(Request $request): void
    {
        Recorder::add('_loadedState');
    }

    public function show($request): Response
    {
        Recorder::add('action:' . get_debug_type($request));

        return new Response('legacy');
    }

    public function _endState(Request $request): void
    {
        Recorder::add('_endState');
    }
}
