<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Testing\TestCase;
use IonsFixture\Auth\FixtureUserProvider;

/*
|--------------------------------------------------------------------------
| Ions\Testing\TestCase — feature coverage
|--------------------------------------------------------------------------
| Written as a PHPUnit-style class (Pest runs class-based tests in the same
| suite) so that the real setUp()/tearDown() lifecycle of the host-facing
| TestCase is exercised exactly as a host application would use it:
| subclass, point $basePath at the app root, call the verb helpers.
*/

class TestCaseTest extends TestCase
{
    protected string $basePath = __DIR__ . '/../../fixtures/app';

    public function test_get_returns_a_test_response_with_working_assertions(): void
    {
        $this->get('/ping')
            ->assertOk()
            ->assertSee('pong');
    }

    public function test_unknown_route_renders_404(): void
    {
        $this->get('/no-such-route')->assertStatus(404);
    }

    public function test_json_post_round_trip_against_the_fixture_echo_endpoint(): void
    {
        $payload = ['name' => 'ion', 'tags' => ['a', 'b'], 'nested' => ['deep' => true]];

        $response = $this->json('POST', '/api/echo', $payload);

        $response->assertOk()
            ->assertHeader('Content-Type')
            ->assertJson(['json' => $payload])
            ->assertJsonPath('json.tags.1', 'b')
            ->assertJsonPath('json.nested.deep', true);

        // json() sends proper JSON headers.
        $this->assertSame('application/json', $response->json('content_type'));
    }

    public function test_protected_api_route_returns_401_without_credentials(): void
    {
        $this->get('/api/secret')->assertStatus(401);
    }

    public function test_acting_as_a_user_id_reaches_a_protected_api_route(): void
    {
        $this->actingAs('user-99')
            ->get('/api/secret')
            ->assertOk()
            ->assertSee('user-99');
    }

    public function test_acting_as_an_authenticatable_object_with_extra_claims(): void
    {
        $user = (new FixtureUserProvider())->retrieveById('user-7');
        $this->assertNotNull($user);

        $this->actingAs($user, ['scope' => 'tests'])
            ->get('/api/secret')
            ->assertOk()
            ->assertSee('user-7');
    }

    public function test_with_token_garbage_yields_401(): void
    {
        $this->withToken('garbage')
            ->get('/api/secret')
            ->assertStatus(401);
    }

    public function test_default_headers_merge_into_requests_and_per_call_headers_win(): void
    {
        $this->withHeaders(['X-Custom' => 'default']);

        // The stored default header rides along on every request…
        $this->json('POST', '/api/echo')->assertJsonPath('x_custom', 'default');

        // …and a per-call header overrides the stored default.
        $this->json('POST', '/api/echo', [], ['X-Custom' => 'override'])
            ->assertJsonPath('x_custom', 'override');

        // The stored default is untouched by the per-call override.
        $this->json('POST', '/api/echo')->assertJsonPath('x_custom', 'default');
    }

    public function test_flush_headers_resets_stored_defaults(): void
    {
        $this->actingAs('user-99');
        $this->get('/api/secret')->assertOk();

        $this->flushHeaders();
        $this->get('/api/secret')->assertStatus(401);
    }

    public function test_acting_as_throws_a_clear_error_when_no_jwt_is_configured(): void
    {
        // Simulate a host without a usable APP_KEY: the container 'jwt'
        // binding resolves to null (exactly what Kernel::buildJwt() yields
        // when APP_KEY is missing or shorter than 32 bytes).
        Kernel::app()->singleton('jwt', static fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');

        $this->actingAs('user-99');
    }

    public function test_the_kernel_is_booted_against_the_configured_base_path(): void
    {
        $this->assertSame('IonsFixture', config('app.name'));
    }
}
