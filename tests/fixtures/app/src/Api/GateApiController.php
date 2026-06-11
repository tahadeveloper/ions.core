<?php

declare(strict_types=1);

namespace IonsFixture\Api;

use Ions\Foundation\ApiController;
use Ions\Support\Request;
use IonsFixture\Gate\FixturePost;
use Symfony\Component\HttpFoundation\Response;

/**
 * Api fixture controller for the Gate feature tests (Phase 10.4).
 *
 * Reached through the api pipeline (AuthMiddleware + FixtureUserProvider),
 * so the gate sees the real 'auth_user' request attribute — the honest
 * end-to-end path for ApiController::authorize().
 */
class GateApiController extends ApiController
{
    /** Ability check: 'view-secret' allows user-99 only. */
    public function secret(Request $request): Response
    {
        $this->authorize('view-secret');

        return new Response('gate secret granted');
    }

    /** Policy check: FixturePostPolicy::update — owner (user-99) only. */
    public function updatePost(Request $request): Response
    {
        $this->authorize('update', new FixturePost('user-99'));

        return new Response('post updated');
    }
}
