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

/**
 * Secure, user-bound, expiring JWT (1.x backport — minimal token version).
 *
 * HMAC-SHA256 signed access tokens with expiry + issuer/audience binding and a
 * reserved-claim guard. This replaces the broken legacy AppKeys scheme (which
 * used an RSA public key as an HMAC secret, with no expiry and no user binding,
 * so any token was valid forever for everyone).
 *
 * The v2 refresh-token / revocation extras are intentionally OMITTED here to
 * keep the 1.x patch minimal.
 */
final class Jwt
{
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
    private const RESERVED_CLAIMS = ['typ', 'jti', 'iss', 'aud', 'sub', 'iat', 'nbf', 'exp'];

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

        // Reject non-access tokens from being used as access tokens.
        $typ = $parsed->claims()->get('typ');
        if ($typ !== null && $typ !== 'access') {
            throw new TokenException('Invalid token: wrong token type.');
        }

        return new Claims(
            userId: (string) $parsed->claims()->get('sub'),
            all: $parsed->claims()->all(),
        );
    }
}
