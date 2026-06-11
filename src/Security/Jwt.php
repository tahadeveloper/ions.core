<?php

declare(strict_types=1);

namespace Ions\Security;

use DateTimeImmutable;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;

final class Jwt
{
    /** Default refresh token lifetime: 14 days in seconds */
    public const DEFAULT_REFRESH_TTL = 1_209_600;

    private Configuration $config;

    /**
     * @phpstan-param non-empty-string $issuer
     * @phpstan-param non-empty-string $audience
     */
    public function __construct(
        string $secret,
        /** @phpstan-var non-empty-string */
        private string $issuer,
        /** @phpstan-var non-empty-string */
        private string $audience,
        private int $ttlSeconds = 3600,
        private int $clockLeewaySeconds = 0,
        private ?RevocationStore $revocations = null,
        private int $refreshTtlSeconds = self::DEFAULT_REFRESH_TTL,
    ) {
        if (strlen($secret) < 32) {
            throw new TokenException('JWT secret must be at least 32 bytes.');
        }
        if ($issuer === '') {
            throw new TokenException('JWT issuer must not be empty.');
        }
        if ($audience === '') {
            throw new TokenException('JWT audience must not be empty.');
        }
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

    /** Reserved claims that callers must not override. */
    private const RESERVED_CLAIMS = ['typ', 'jti', 'iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'fid'];

    /** @param array<non-empty-string,mixed> $claims */
    public function issue(string $userId, array $claims = []): string
    {
        if ($userId === '') {
            throw new TokenException('userId must not be empty.');
        }
        $now = new DateTimeImmutable();
        $builder = $this->config->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->audience)
            ->relatedTo($userId)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $this->ttlSeconds)));
        // Apply caller claims first (reserved keys are silently skipped).
        foreach ($claims as $k => $v) {
            if (!in_array($k, self::RESERVED_CLAIMS, true)) {
                $builder = $builder->withClaim($k, $v);
            }
        }
        // Framework-controlled claims are always applied last so they cannot be overridden.
        $builder = $builder->withClaim('typ', 'access');
        return $builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
    }

    /**
     * Issue a refresh token for the given user.
     *
     * Refresh tokens have a longer TTL and a `typ` claim of `'refresh'`.
     * They can only be used with `refresh()`, not `verify()`.
     *
     * A fresh family id (`fid`) is minted here — at login — and carried forward
     * onto every rotated refresh token in this lineage so the breach-detection
     * pattern can revoke the whole family on a replay (see {@see refresh()}).
     */
    public function issueRefresh(string $userId): string
    {
        if ($userId === '') {
            throw new TokenException('userId must not be empty.');
        }

        return $this->buildRefreshToken($userId, bin2hex(random_bytes(16)));
    }

    /**
     * Build a refresh token bound to a user and a refresh-token family id.
     *
     * `fid` is framework-set (it is a reserved claim and cannot be supplied by
     * callers), so this stays internal: login mints a new fid; rotation reuses
     * the presented token's fid.
     *
     * @phpstan-param non-empty-string $userId
     * @phpstan-param non-empty-string $fid
     */
    private function buildRefreshToken(string $userId, string $fid): string
    {
        $now = new DateTimeImmutable();
        $builder = $this->config->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->audience)
            ->relatedTo($userId)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $this->refreshTtlSeconds)))
            ->withClaim('typ', 'refresh')
            ->withClaim('fid', $fid);
        return $builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
    }

    public function verify(string $token): Claims
    {
        if ($token === '') {
            throw new TokenException('Token must not be empty.');
        }
        try {
            $parsed = $this->config->parser()->parse($token);
            $clock = SystemClock::fromSystemTimezone();
            $validAt = $this->clockLeewaySeconds > 0
                ? new StrictValidAt($clock, new \DateInterval('PT' . $this->clockLeewaySeconds . 'S'))
                : new StrictValidAt($clock);
            $this->config->validator()->assert(
                $parsed,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                $validAt,
                new IssuedBy($this->issuer),
                new PermittedFor($this->audience),
            );
        } catch (\Throwable $e) {
            throw new TokenException('Invalid token: ' . $e->getMessage(), 0, $e);
        }
        if (!$parsed instanceof UnencryptedToken) {
            throw new TokenException('Token is not a parseable claims token.');
        }

        // Reject refresh tokens from being used as access tokens.
        $typ = $parsed->claims()->get('typ');
        if ($typ !== null && $typ !== 'access') {
            throw new TokenException('Invalid token: wrong token type.');
        }

        // Check revocation store.
        if ($this->revocations !== null) {
            $jti = (string) $parsed->claims()->get('jti', '');
            if ($jti !== '' && $this->revocations->isRevoked($jti)) {
                throw new TokenException('Token revoked.');
            }
        }

        return new Claims(
            userId: (string) $parsed->claims()->get('sub'),
            all: $parsed->claims()->all(),
        );
    }

    /**
     * Revoke a token (access or refresh) by adding its jti to the deny-list.
     *
     * When no revocation store is configured this is a no-op.
     */
    public function revoke(string $token): void
    {
        if ($this->revocations === null) {
            return;
        }

        $parsed = $this->parseUnchecked($token);
        if ($parsed === null) {
            return;
        }

        $jti = (string) $parsed->claims()->get('jti', '');
        if ($jti === '') {
            return;
        }

        $exp = $parsed->claims()->get('exp');
        $ttl = 0;
        if ($exp instanceof \DateTimeImmutable) {
            $ttl = max(0, $exp->getTimestamp() - time());
        }

        $this->revocations->revoke($jti, $ttl);
    }

    /**
     * Exchange a valid refresh token for a fresh access + refresh token pair
     * (rotation with breach detection — the "revoke-all-on-replay" pattern).
     *
     * - Validates signature, time constraints, issuer, audience.
     * - Requires `typ === 'refresh'` (rejects access tokens).
     * - Family short-circuit: if the token's `fid` family is already revoked
     *   (a sibling from a compromised lineage) → reject.
     * - Reuse detection: if the presented `jti` is already revoked (i.e. it was
     *   already rotated and is being replayed) → revoke the ENTIRE `fid` family
     *   so every sibling is killed, then reject.
     * - Happy path: revoke the presented `jti` (rotation) and re-issue BOTH a
     *   new access token and a new refresh token carrying the SAME `fid`.
     *
     * Backward compatibility: refresh tokens issued before 11.4 carry no `fid`.
     * For those, the family checks are skipped (there is no lineage to revoke);
     * the per-jti rotation/reuse semantics still apply, and the rotated token is
     * minted with a fresh `fid` so it joins the family-aware scheme going forward.
     *
     * @return array{access: string, refresh: string} the new token pair
     *
     * @throws TokenException if the refresh token is invalid, expired, revoked,
     *                        replayed, from a revoked family, or the wrong type.
     */
    public function refresh(string $refreshToken): array
    {
        if ($refreshToken === '') {
            throw new TokenException('Refresh token must not be empty.');
        }
        try {
            $parsed = $this->config->parser()->parse($refreshToken);
            $clock = SystemClock::fromSystemTimezone();
            $validAt = $this->clockLeewaySeconds > 0
                ? new StrictValidAt($clock, new \DateInterval('PT' . $this->clockLeewaySeconds . 'S'))
                : new StrictValidAt($clock);
            $this->config->validator()->assert(
                $parsed,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                $validAt,
                new IssuedBy($this->issuer),
                new PermittedFor($this->audience),
            );
        } catch (\Throwable $e) {
            throw new TokenException('Invalid refresh token: ' . $e->getMessage(), 0, $e);
        }

        if (!$parsed instanceof UnencryptedToken) {
            throw new TokenException('Refresh token is not a parseable claims token.');
        }

        // Must be a refresh token — reject access tokens.
        $typ = $parsed->claims()->get('typ');
        if ($typ !== 'refresh') {
            throw new TokenException('Invalid token type: expected refresh token.');
        }

        $jti = (string) $parsed->claims()->get('jti', '');
        $fid = (string) $parsed->claims()->get('fid', '');

        if ($this->revocations !== null) {
            // Family short-circuit: a sibling from a killed lineage is rejected
            // before doing any work (BC: a legacy token with no fid is never a
            // family member, so this never fires for it).
            if ($fid !== '' && $this->revocations->isFamilyRevoked($fid)) {
                throw new TokenException('Refresh token family has been revoked.');
            }

            // Reuse detection: an already-revoked jti being presented again is a
            // replay of a token that was already rotated. Kill the whole family
            // (so every still-live sibling is invalidated), then reject.
            if ($jti !== '' && $this->revocations->isRevoked($jti)) {
                if ($fid !== '') {
                    $this->revocations->revokeFamily($fid, $this->refreshTtlSeconds);
                }
                throw new TokenException('Refresh token has already been used or revoked.');
            }
        }

        $userId = (string) $parsed->claims()->get('sub');
        if ($userId === '') {
            throw new TokenException('Refresh token is missing its subject.');
        }

        // Rotate: revoke the presented refresh token so it cannot be reused.
        if ($this->revocations !== null && $jti !== '') {
            $exp = $parsed->claims()->get('exp');
            $ttl = 0;
            if ($exp instanceof \DateTimeImmutable) {
                $ttl = max(0, $exp->getTimestamp() - time());
            }
            $this->revocations->revoke($jti, $ttl);
        }

        // Carry the family forward; a legacy (no-fid) token starts a fresh family.
        $nextFid = $fid !== '' ? $fid : bin2hex(random_bytes(16));

        return [
            'access' => $this->issue($userId),
            'refresh' => $this->buildRefreshToken($userId, $nextFid),
        ];
    }

    /**
     * Parse a token string without validating constraints.
     * Returns null on any parse failure.
     */
    private function parseUnchecked(string $token): ?UnencryptedToken
    {
        if ($token === '') {
            return null;
        }
        try {
            $parsed = $this->config->parser()->parse($token);
            return $parsed instanceof UnencryptedToken ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
