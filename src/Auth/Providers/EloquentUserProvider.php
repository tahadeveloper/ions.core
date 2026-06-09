<?php

declare(strict_types=1);

namespace Ions\Auth\Providers;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;
use Ions\Support\DB;

/**
 * A native Sentinel-free UserProvider backed by a plain database table.
 *
 * Reads table / column names from config:
 *   auth.table      – table name (default: 'users')
 *   auth.identifier – login identifier column (default: 'email')
 *   auth.password   – password-hash column (default: 'password')
 *   auth.id         – primary key column (default: 'id')
 */
final class EloquentUserProvider implements UserProvider
{
    private string $table;
    private string $identifierColumn;
    private string $passwordColumn;
    private string $idColumn;

    public function __construct()
    {
        $this->table            = (string) config('auth.table', 'users');
        $this->identifierColumn = (string) config('auth.identifier', 'email');
        $this->passwordColumn   = (string) config('auth.password', 'password');
        $this->idColumn         = (string) config('auth.id', 'id');
    }

    public function retrieveById(string|int $id): ?Authenticatable
    {
        $row = DB::connection()
            ->table($this->table)
            ->where($this->idColumn, $id)
            ->first();

        return $row instanceof \stdClass ? $this->adapt($row) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $identifier = $credentials[$this->identifierColumn] ?? null;

        if ($identifier === null) {
            return null;
        }

        $row = DB::connection()
            ->table($this->table)
            ->where($this->identifierColumn, $identifier)
            ->first();

        return $row instanceof \stdClass ? $this->adapt($row) : null;
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (!$user instanceof EloquentUserAdapter) {
            return false;
        }

        $plain = (string) ($credentials['password'] ?? '');

        return password_verify($plain, $user->getAuthPassword());
    }

    private function adapt(\stdClass $row): EloquentUserAdapter
    {
        return new EloquentUserAdapter($row, $this->idColumn, $this->passwordColumn);
    }
}
