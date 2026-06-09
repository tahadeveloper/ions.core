<?php

declare(strict_types=1);

namespace Ions\Auth\Contracts;

interface Authenticatable
{
    /** The unique identifier for the user (e.g. primary key). */
    public function getAuthIdentifier(): string|int;

    /** The name of the unique-identifier field (e.g. 'id'). */
    public function getAuthIdentifierName(): string;
}
