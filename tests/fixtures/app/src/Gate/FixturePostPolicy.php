<?php

declare(strict_types=1);

namespace IonsFixture\Gate;

use Ions\Auth\Contracts\Authenticatable;

/**
 * Policy fixture registered by FixtureGateProvider for FixturePost.
 * Method names are ability names; the signature's $user nullability
 * drives the gate's guest semantics.
 */
final class FixturePostPolicy
{
    /** Owners only — non-nullable $user, so guests are auto-denied. */
    public function update(Authenticatable $user, FixturePost $post): bool
    {
        return (string) $user->getAuthIdentifier() === $post->ownerId;
    }

    /** Guest-friendly — nullable $user reaches the body (as null). */
    public function view(?Authenticatable $user, FixturePost $post): bool
    {
        return true;
    }
}
