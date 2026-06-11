<?php

use Ions\Security\ArrayRevocationStore;
use Ions\Security\Jwt;
use Ions\Security\TokenException;

test('issueRefresh creates a refresh token that refresh() exchanges for a new access token', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $refresh = $jwt->issueRefresh('42');
    $rotated = $jwt->refresh($refresh);
    expect($rotated)->toBeArray()
        ->and($rotated)->toHaveKeys(['access', 'refresh']);
    expect($jwt->verify($rotated['access'])->userId)->toBe('42');
});

test('refresh re-issues a NEW refresh token (different jti, same fid)', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $refresh = $jwt->issueRefresh('42');

    $rotated = $jwt->refresh($refresh);

    // The new refresh token is usable for a further rotation (proves typ=refresh + same fid lineage).
    $again = $jwt->refresh($rotated['refresh']);
    expect($again)->toBeArray()->toHaveKeys(['access', 'refresh']);
    expect($jwt->verify($again['access'])->userId)->toBe('42');
});

test('an access token cannot be used as a refresh token', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $access = $jwt->issue('42');
    expect(fn () => $jwt->refresh($access))->toThrow(TokenException::class);
});

test('a refresh token cannot be used as an access token (verify rejects typ=refresh)', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $refresh = $jwt->issueRefresh('42');
    expect(fn () => $jwt->verify($refresh))->toThrow(TokenException::class);
});

test('refresh rotates: the old refresh token is revoked after use', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);
    $refresh = $jwt->issueRefresh('42');
    $jwt->refresh($refresh);
    expect(fn () => $jwt->refresh($refresh))->toThrow(TokenException::class); // old refresh now revoked
});

test('caller cannot override typ to forge a refresh token via issue()', function () {
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, new ArrayRevocationStore());
    $sneaky = $jwt->issue('42', ['typ' => 'refresh']); // attempt to forge a refresh token
    // it must remain an ACCESS token: verify() accepts it, refresh() rejects it
    expect($jwt->verify($sneaky)->userId)->toBe('42');
    expect(fn () => $jwt->refresh($sneaky))->toThrow(TokenException::class);
});

test('reuse detection: re-presenting a rotated refresh token kills the whole family', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);

    // Issue a refresh token and rotate it twice to mint a sibling in the SAME family.
    $r0 = $jwt->issueRefresh('42');
    $rot1 = $jwt->refresh($r0);           // r0 revoked; rot1.refresh is a sibling of the family
    $sibling = $rot1['refresh'];

    // The attacker replays the already-rotated r0 → reuse signal → revoke the whole family.
    expect(fn () => $jwt->refresh($r0))->toThrow(TokenException::class);

    // The still-"valid" sibling token from the same family is now also rejected.
    expect(fn () => $jwt->refresh($sibling))->toThrow(TokenException::class);
});

test('isFamilyRevoked short-circuits: a sibling of a killed family is rejected up-front', function () {
    $store = new ArrayRevocationStore();
    $jwt = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', 3600, 0, $store);

    $r0 = $jwt->issueRefresh('42');
    $rot1 = $jwt->refresh($r0);
    $sibling = $rot1['refresh'];

    // Read the fid off the sibling and revoke the family directly.
    $fid = (string) decodeJwtClaim($sibling, 'fid');
    expect($fid)->not->toBe('');
    $store->revokeFamily($fid, 3600);

    expect($store->isFamilyRevoked($fid))->toBeTrue();
    expect(fn () => $jwt->refresh($sibling))->toThrow(TokenException::class);
});

test('BC: a legacy refresh token with no fid still refreshes (family checks skipped)', function () {
    $store = new ArrayRevocationStore();
    $secret = str_repeat('a', 32);

    // Forge a legacy refresh token without a fid claim using a fid-unaware Jwt build.
    $legacy = makeLegacyRefreshToken($secret, 'ions', 'ions-app', '42');

    $jwt = new Jwt($secret, 'ions', 'ions-app', 3600, 0, $store);
    $rotated = $jwt->refresh($legacy);
    expect($rotated)->toBeArray()->toHaveKeys(['access', 'refresh']);
    expect($jwt->verify($rotated['access'])->userId)->toBe('42');
});

/** Decode a single claim from a JWT's payload segment without verifying it. */
function decodeJwtClaim(string $jwt, string $claim): mixed
{
    $parts = explode('.', $jwt);
    $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

    return is_array($payload) ? ($payload[$claim] ?? null) : null;
}

/** Build a refresh token WITHOUT a fid claim, mimicking a pre-11.4 issueRefresh(). */
function makeLegacyRefreshToken(string $secret, string $issuer, string $audience, string $userId): string
{
    $config = \Lcobucci\JWT\Configuration::forSymmetricSigner(
        new \Lcobucci\JWT\Signer\Hmac\Sha256(),
        \Lcobucci\JWT\Signer\Key\InMemory::plainText($secret),
    );
    $now = new \DateTimeImmutable();

    return $config->builder()
        ->issuedBy($issuer)
        ->permittedFor($audience)
        ->relatedTo($userId)
        ->identifiedBy(bin2hex(random_bytes(16)))
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($now->modify('+1209600 seconds'))
        ->withClaim('typ', 'refresh')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();
}
