<?php

namespace Ions\Auth\Providers;

use Cartalyst\Sentinel\Native\Facades\Sentinel;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;

/**
 * UserProvider that delegates to the Cartalyst Sentinel package.
 *
 * This is the DEFAULT provider (auth.provider = 'sentinel').
 * Sentinel must already be bootstrapped (Guard::constructStatic() or equivalent).
 */
final class SentinelUserProvider implements UserProvider
{
    public function retrieveById(string|int $id): ?Authenticatable
    {
        $user = Sentinel::findById((int) $id);

        return $user !== null ? new SentinelUserAdapter($user) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $user = Sentinel::findByCredentials($credentials);

        return $user !== null ? new SentinelUserAdapter($user) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (!$user instanceof SentinelUserAdapter) {
            return false;
        }

        return Sentinel::getUserRepository()->validateCredentials(
            $user->getSentinelUser(),
            $credentials,
        );
    }
}
