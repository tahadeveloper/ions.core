<?php

declare(strict_types=1);

namespace Ions\Auth;

use Illuminate\Contracts\Cache\Repository;

/**
 * Optional replay protection for TOTP codes.
 *
 * RFC 6238 §5.2 recommends rejecting a one-time password that has already been
 * accepted within its validity window. This store keeps {@see TwoFactor} pure:
 * it verifies the submitted code, then records the exact time-step it matched
 * (scoped per user) in the cache so the same step cannot be replayed while the
 * window is still open.
 *
 * Wire it against the container's `cache.store` repository:
 *
 *     $store = new TwoFactorReplayStore(app('cache.store'));
 *     if ($store->verifyOnce($user->id, $secret, $code)) { ... }
 */
final class TwoFactorReplayStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'totp_used:',
    ) {
    }

    /**
     * Verify a code and atomically consume the matching time-step.
     *
     * Returns true only when the code is valid (within ±$window drift) AND the
     * step it matched has not already been consumed for this user. A wrong code
     * returns false without consuming anything.
     *
     * @param string|int $userId stable identifier for the account being verified
     */
    public function verifyOnce(
        string|int $userId,
        string $secret,
        string $code,
        int $window = 1,
        ?int $timestamp = null,
        int $period = 30,
        int $digits = 6,
        string $algo = 'sha1',
    ): bool {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $counter + $offset;
            if (!hash_equals(TwoFactor::code($secret, $step * $period, $period, $digits, $algo), $code)) {
                continue;
            }

            if ($this->wasUsed($userId, $step)) {
                return false;
            }

            // Hold the marker until the whole acceptance window has elapsed.
            $this->markUsed($userId, $step, ($window + 1) * $period);

            return true;
        }

        return false;
    }

    /**
     * Record that a specific time-step has been consumed for a user.
     */
    public function markUsed(string|int $userId, int $step, int $ttlSeconds): void
    {
        $this->cache->put($this->key($userId, $step), true, max(1, $ttlSeconds));
    }

    /**
     * Whether a time-step has already been consumed for a user.
     */
    public function wasUsed(string|int $userId, int $step): bool
    {
        return (bool) $this->cache->get($this->key($userId, $step), false);
    }

    private function key(string|int $userId, int $step): string
    {
        return $this->prefix . $userId . ':' . $step;
    }
}
