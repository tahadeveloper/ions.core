<?php

declare(strict_types=1);

use Ions\Auth\Gate;
use Ions\Foundation\Kernel;
use Ions\Testing\TestCase;

/*
|--------------------------------------------------------------------------
| Gate & policies through the booted kernel (Phase 10.4)
|--------------------------------------------------------------------------
| Fixture pieces:
| - IonsFixture\Providers\FixtureGateProvider (auto-discovered): defines the
|   'open-door' (nullable $user), 'members-area' (non-nullable $user) and
|   'view-secret' (allows user-99 only) abilities, and registers
|   FixturePostPolicy for FixturePost — mirroring the documented host
|   AuthServiceProvider convention.
| - Web routes /gate/* (no auth pipeline → guest) exercising
|   BaseController::authorize(), the can() helper and the Twig can() function.
| - Api routes /api/gate/* behind AuthMiddleware: actingAs() issues a real
|   JWT, FixtureUserProvider resolves the user, and the gate reads it from
|   the request's 'auth_user' attribute — the honest end-to-end path.
*/

class GatePipelineTest extends TestCase
{
    protected string $basePath = __DIR__ . '/../../fixtures/app';

    public function test_the_gate_is_bound_as_a_lazy_singleton_with_a_class_alias(): void
    {
        $gate = Kernel::app()->get('gate');

        $this->assertInstanceOf(Gate::class, $gate);
        $this->assertSame($gate, Kernel::app()->get(Gate::class));
    }

    // --- web pipeline (guest — no auth middleware on web routes) -------------

    public function test_web_authorize_allows_a_guest_through_a_nullable_ability(): void
    {
        $this->get('/gate/web-open')
            ->assertOk()
            ->assertSee('door open');
    }

    public function test_web_authorize_denies_a_guest_on_a_non_nullable_ability_with_403(): void
    {
        $this->get('/gate/web-members')->assertStatus(403);
    }

    // --- api pipeline (real JWT via actingAs, user from FixtureUserProvider) --

    public function test_api_authorize_allows_the_permitted_user(): void
    {
        $this->actingAs('user-99')
            ->get('/api/gate/secret')
            ->assertOk()
            ->assertSee('gate secret granted');
    }

    public function test_api_authorize_denies_another_authenticated_user_with_403(): void
    {
        $this->actingAs('user-7')
            ->get('/api/gate/secret')
            ->assertStatus(403);
    }

    public function test_api_policy_allows_the_owner_and_denies_others(): void
    {
        // FixturePostPolicy::update — the fixture post is owned by user-99.
        $this->actingAs('user-99')
            ->get('/api/gate/post-update')
            ->assertOk()
            ->assertSee('post updated');

        $this->actingAs('user-7')
            ->get('/api/gate/post-update')
            ->assertStatus(403);
    }

    // --- can() helper ----------------------------------------------------------

    public function test_can_helper_resolves_the_gate_for_the_current_request_user(): void
    {
        $this->get('/gate/helper')
            ->assertOk()
            ->assertJson(['open' => true, 'members' => false]);
    }

    // --- Twig can() --------------------------------------------------------------

    public function test_twig_can_function_renders_gate_results(): void
    {
        config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

        $this->get('/gate/view')
            ->assertOk()
            ->assertSee('door: open')
            ->assertSee('members: out');
    }
}
