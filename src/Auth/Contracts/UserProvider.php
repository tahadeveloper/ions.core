<?php

namespace Ions\Auth\Contracts;

interface UserProvider
{
    public function retrieveById(string|int $id): ?Authenticatable;

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}
