<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers;

use Ions\Foundation\BaseController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web fixture controller for the Gate feature tests (Phase 10.4).
 *
 * Web routes carry no auth pipeline, so every request here is a GUEST —
 * the two actions exercise BaseController::authorize() against a
 * guest-friendly (nullable $user) and a members-only (non-nullable $user)
 * ability defined by FixtureGateProvider.
 */
class GatePagesController extends BaseController
{
    /** 'open-door' has a nullable $user — a guest passes authorize(). */
    public function open(): Response
    {
        $this->authorize('open-door');

        return new Response('door open');
    }

    /** 'members-area' requires a user — a guest is auto-denied (403). */
    public function members(): Response
    {
        $this->authorize('members-area');

        return new Response('members only');
    }
}
