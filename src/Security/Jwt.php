<?php

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

    public function __construct(
        string $secret,
        private string $issuer,
        private string $audience,
        private int $ttlSeconds = 3600,
        private int $clockLeewaySeconds = 0,
        private ?RevocationStore $revocations = null,
        private int $refreshTtlSeconds = self::DEFAULT_REFRESH_TTL,
    ) {
        if (strlen($secret) < 32) {
            throw new TokenException('JWT secret must be at least 32 bytes.');
        }
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

    /** Reserved claims that callers must not override. */
    private const RESERVED_CLAIMS = ['typ', 'jti', 'iss', 'aud', 'sub', 'iat', 'nbf', 'exp'];

    public function issue(string $userId, array $claims = []): string
    {
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
     */
    public function issueRefresh(string $userId): string
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
            ->withClaim('typ', 'refresh');
        return $builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
    }

    public function verify(string $token): Claims
    {
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
     * Exchange a valid refresh token for a new access token (with rotation).
     *
     * - Validates signature, time constraints, issuer, audience.
     * - Requires `typ === 'refresh'` (rejects access tokens).
     * - Checks revocation store so a rotated (used) refresh token is rejected.
     * - Revokes the presented refresh token (rotation).
     * - Returns a fresh access token string.
     *
     * @throws TokenException if the refresh token is invalid, expired, revoked, or wrong type.
     */
    public function refresh(string $refreshToken): string
    {
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

        // Check revocation (handles rotation: used refresh tokens are revoked).
        $jti = (string) $parsed->claims()->get('jti', '');
        if ($this->revocations !== null && $jti !== '' && $this->revocations->isRevoked($jti)) {
            throw new TokenException('Refresh token has already been used or revoked.');
        }

        $userId = (string) $parsed->claims()->get('sub');

        // Rotate: revoke the presented refresh token so it cannot be reused.
        if ($this->revocations !== null && $jti !== '') {
            $exp = $parsed->claims()->get('exp');
            $ttl = 0;
            if ($exp instanceof \DateTimeImmutable) {
                $ttl = max(0, $exp->getTimestamp() - time());
            }
            $this->revocations->revoke($jti, $ttl);
        }

        // Issue and return a fresh access token.
        return $this->issue($userId);
    }

    /**
     * Parse a token string without validating constraints.
     * Returns null on any parse failure.
     */
    private function parseUnchecked(string $token): ?UnencryptedToken
    {
        try {
            $parsed = $this->config->parser()->parse($token);
            return $parsed instanceof UnencryptedToken ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
