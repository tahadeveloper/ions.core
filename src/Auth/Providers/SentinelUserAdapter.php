<?php

namespace Ions\Auth\Providers;

use Cartalyst\Sentinel\Users\UserInterface;
use Ions\Auth\Contracts\Authenticatable;

/**
 * Wraps a Cartalyst Sentinel UserInterface into the Authenticatable contract.
 *
 * @internal Used exclusively by SentinelUserProvider.
 */
final class SentinelUserAdapter implements Authenticatable
{
    public function __construct(
        private readonly UserInterface $sentinelUser,
    ) {
    }

    public function getAuthIdentifier(): int
    {
        return $this->sentinelUser->getUserId();
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Exposes the underlying Sentinel user so the provider can call
     * getUserRepository()->validateCredentials() with the original model.
     */
    public function getSentinelUser(): UserInterface
    {
        return $this->sentinelUser;
    }
}
