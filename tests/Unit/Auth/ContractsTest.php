<?php

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;

test('a UserProvider returns an Authenticatable by id and null for unknown', function () {
    $known = new class () implements Authenticatable {
        public function getAuthIdentifier(): string|int
        {
            return '42';
        }
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }
    };
    $provider = new class ($known) implements UserProvider {
        public function __construct(private Authenticatable $user)
        {
        }
        public function retrieveById(string|int $id): ?Authenticatable
        {
            return (string) $id === '42' ? $this->user : null;
        }
        public function retrieveByCredentials(array $credentials): ?Authenticatable
        {
            return ($credentials['id'] ?? null) === '42' ? $this->user : null;
        }
        public function validateCredentials(Authenticatable $user, array $credentials): bool
        {
            return ($credentials['password'] ?? null) === 'secret';
        }
    };

    expect($provider->retrieveById('42'))->toBe($known)
        ->and($provider->retrieveById('999'))->toBeNull()
        ->and($provider->retrieveById('42')->getAuthIdentifier())->toBe('42')
        ->and($provider->retrieveByCredentials(['id' => '42']))->toBe($known)
        ->and($provider->validateCredentials($known, ['password' => 'secret']))->toBeTrue()
        ->and($provider->validateCredentials($known, ['password' => 'wrong']))->toBeFalse();
});
