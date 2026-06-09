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
    private Configuration $config;

    public function __construct(
        string $secret,
        private string $issuer,
        private string $audience,
        private int $ttlSeconds = 3600,
        private int $clockLeewaySeconds = 0,
    ) {
        if (strlen($secret) < 32) {
            throw new TokenException('JWT secret must be at least 32 bytes.');
        }
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

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
        foreach ($claims as $k => $v) {
            $builder = $builder->withClaim($k, $v);
        }
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
        return new Claims(
            userId: (string) $parsed->claims()->get('sub'),
            all: $parsed->claims()->all(),
        );
    }
}
