<?php

namespace IonsFixture\Auth;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;

/**
 * Minimal in-memory UserProvider for the test fixture.
 *
 * Known users are hard-coded so integration tests can exercise the
 * "user resolves → 200" path without a real database or Sentinel.
 *
 * Credential login: 'known@example.com' / 'secret' → user-99.
 */
final class FixtureUserProvider implements UserProvider
{
    /** @var array<string, string> id => display */
    private array $knownIds = ['user-99' => 'user-99', 'user-7' => 'user-7'];

    /** @var array<string, array{id: string, password: string}> email => record */
    private array $byEmail = [
        'known@example.com' => ['id' => 'user-99', 'password' => 'secret'],
    ];

    public function retrieveById(string|int $id): ?Authenticatable
    {
        $key = (string) $id;
        if (!isset($this->knownIds[$key])) {
            return null;
        }

        return $this->makeUser($key);
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = (string) ($credentials['email'] ?? '');
        if (!isset($this->byEmail[$email])) {
            return null;
        }

        return $this->makeUser($this->byEmail[$email]['id']);
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $id = (string) $user->getAuthIdentifier();
        $password = (string) ($credentials['password'] ?? '');

        foreach ($this->byEmail as $record) {
            if ($record['id'] === $id) {
                return hash_equals($record['password'], $password);
            }
        }

        return false;
    }

    private function makeUser(string $id): Authenticatable
    {
        return new class ($id) implements Authenticatable {
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
}
