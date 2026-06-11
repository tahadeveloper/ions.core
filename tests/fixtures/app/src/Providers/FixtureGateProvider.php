<?php

declare(strict_types=1);

namespace IonsFixture\Providers;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Gate;
use Ions\Container\ServiceProvider;
use IonsFixture\Gate\FixturePost;
use IonsFixture\Gate\FixturePostPolicy;

/**
 * Gate fixture provider (Phase 10.4) — mirrors the documented host
 * convention (an auto-discovered app/Providers/AuthServiceProvider that
 * defines abilities and registers policies in boot()).
 */
final class FixtureGateProvider extends ServiceProvider
{
    public function register(): void
    {
        // Definitions live in boot(): the 'gate' singleton is registered by
        // the framework's AuthProvider during the register phase.
    }

    public function boot(): void
    {
        /** @var Gate $gate */
        $gate = $this->container->get('gate');

        // Guest-friendly: nullable $user — guests reach the callback as null.
        $gate->define('open-door', static fn (?Authenticatable $user): bool => true);

        // Members only: non-nullable $user — guests are auto-denied.
        $gate->define('members-area', static fn (Authenticatable $user): bool => true);

        // Identity check used by the /api/gate/secret fixture.
        $gate->define(
            'view-secret',
            static fn (?Authenticatable $user): bool => $user !== null && (string) $user->getAuthIdentifier() === 'user-99'
        );

        $gate->policy(FixturePost::class, FixturePostPolicy::class);
    }
}
