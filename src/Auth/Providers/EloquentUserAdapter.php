<?php

namespace Ions\Auth\Providers;

use Ions\Auth\Contracts\Authenticatable;

/**
 * Wraps a stdClass row returned by the query-builder into the Authenticatable contract.
 *
 * @internal Used exclusively by EloquentUserProvider.
 */
final class EloquentUserAdapter implements Authenticatable
{
    public function __construct(
        private readonly \stdClass $row,
        private readonly string $idColumn,
        private readonly string $passwordColumn,
    ) {}

    public function getAuthIdentifier(): string|int
    {
        $col = $this->idColumn;

        return $this->row->{$col};
    }

    public function getAuthIdentifierName(): string
    {
        return $this->idColumn;
    }

    /**
     * Returns the hashed password for credential validation.
     */
    public function getAuthPassword(): string
    {
        $col = $this->passwordColumn;

        return (string) ($this->row->{$col} ?? '');
    }
}
