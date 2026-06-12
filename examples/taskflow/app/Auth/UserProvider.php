<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider as UserProviderContract;

/**
 * Host UserProvider that resolves the application's own {@see User} Eloquent
 * model (rather than the framework's row-adapter), so JWT auth + the Gate see
 * a real model with relationships and the {@see User::hasVerifiedEmail()}
 * contract intact.
 *
 * Wired via config/auth.php `'provider' => App\Auth\UserProvider::class`,
 * which the framework's AuthProvider resolves through the container.
 */
final class UserProvider implements UserProviderContract
{
    public function retrieveById(string|int $id): ?Authenticatable
    {
        return User::query()->find($id);
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if ($email === null) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $plain = (string) ($credentials['password'] ?? '');

        return $plain !== '' && password_verify($plain, (string) $user->password);
    }
}
