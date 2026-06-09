<?php

namespace IonsFixture\Auth;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;

/**
 * Minimal in-memory UserProvider for the test fixture.
 *
 * Known users are hard-coded so integration tests can exercise the
 * "user resolves → 200" path without a real database or Sentinel.
 */
final class FixtureUserProvider implements UserProvider
{
    /** @var array<string, string> id => display */
    private array $knownIds = ['user-99' => 'user-99', 'user-7' => 'user-7'];

    public function retrieveById(string|int $id): ?Authenticatable
    {
        $key = (string) $id;
        if (!isset($this->knownIds[$key])) {
            return null;
        }

        return new class ($key) implements Authenticatable {
            public function __construct(private string $id)
            {
            }

            public function getAuthIdentifier(): string|int
            {
                return $this->id;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }
        };
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }
}
