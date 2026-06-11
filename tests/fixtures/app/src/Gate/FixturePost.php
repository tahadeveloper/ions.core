<?php

declare(strict_types=1);

namespace IonsFixture\Gate;

/**
 * Plain model-ish subject for the gate policy fixtures. The gate maps
 * policies by class, so it does not need to be an Eloquent model.
 */
final class FixturePost
{
    public function __construct(public string $ownerId = 'user-99')
    {
    }
}
