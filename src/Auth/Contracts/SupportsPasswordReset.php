<?php

declare(strict_types=1);

namespace Ions\Auth\Contracts;

/**
 * Optional capability for {@see UserProvider}s that can issue and apply
 * password-reset tokens.
 *
 * Providers that cannot reset passwords (e.g. a read-only or external IdP
 * provider) simply do not implement this interface; the AuthController then
 * reports the reset endpoints as unsupported (HTTP 501) for that provider.
 *
 * The default {@see \Ions\Auth\Providers\SentinelUserProvider} implements this
 * via Sentinel's reminder repository.
 */
interface SupportsPasswordReset
{
    /**
     * Issue a password-reset code for the user matching the given credentials.
     *
     * @param array<string,mixed> $credentials Lookup credentials (e.g. ['email' => ...]).
     * @return string|null The reset code, or null when no matching user exists.
     */
    public function createResetCode(array $credentials): ?string;

    /**
     * Apply a previously issued reset code, setting the user's new password.
     *
     * @param array<string,mixed> $credentials Lookup credentials (e.g. ['email' => ...]).
     * @param string              $code        The reset code issued by createResetCode().
     * @param string              $newPassword The plaintext password to set.
     * @return bool True when the reset was applied successfully.
     */
    public function resetPassword(array $credentials, string $code, string $newPassword): bool;
}
