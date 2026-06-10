<?php

declare(strict_types=1);

namespace Ions\Auth\Providers;

use Cartalyst\Sentinel\Native\Facades\Sentinel;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\SupportsPasswordReset;
use Ions\Auth\Contracts\UserProvider;

/**
 * UserProvider that delegates to the Cartalyst Sentinel package.
 *
 * This is the DEFAULT provider (auth.provider = 'sentinel').
 * Sentinel must already be bootstrapped (Guard::constructStatic() or equivalent).
 */
final class SentinelUserProvider implements UserProvider, SupportsPasswordReset
{
    public function retrieveById(string|int $id): ?Authenticatable
    {
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findById((int) $id);

        return $user !== null ? new SentinelUserAdapter($user) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findByCredentials($credentials);

        return $user !== null ? new SentinelUserAdapter($user) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (!$user instanceof SentinelUserAdapter) {
            return false;
        }

        /** @phpstan-ignore staticMethod.notFound */
        return Sentinel::getUserRepository()->validateCredentials(
            $user->getSentinelUser(),
            $credentials,
        );
    }

    /**
     * Issue a reminder (reset) code for the user matching the credentials.
     *
     * @param array<string,mixed> $credentials
     */
    public function createResetCode(array $credentials): ?string
    {
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findByCredentials($credentials);
        if ($user === null) {
            return null;
        }

        /** @phpstan-ignore staticMethod.notFound */
        $reminder = Sentinel::getReminderRepository()->create($user);

        $code = $reminder->code ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * Complete a reminder with the given code, setting the new password.
     *
     * @param array<string,mixed> $credentials
     */
    public function resetPassword(array $credentials, string $code, string $newPassword): bool
    {
        /** @phpstan-ignore staticMethod.notFound */
        $user = Sentinel::findByCredentials($credentials);
        if ($user === null) {
            return false;
        }

        /** @phpstan-ignore staticMethod.notFound */
        return (bool) Sentinel::getReminderRepository()->complete($user, $code, $newPassword);
    }
}
