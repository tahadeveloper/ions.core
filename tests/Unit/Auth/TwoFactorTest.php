<?php

declare(strict_types=1);

use Ions\Auth\TwoFactor;

/**
 * RFC 6238 Appendix B reference vectors.
 *
 * Seeds (ASCII):
 *   SHA1   = "12345678901234567890"                                             (20 bytes)
 *   SHA256 = "12345678901234567890123456789012"                                 (32 bytes)
 *   SHA512 = "1234567890123456789012345678901234567890123456789012345678901234" (64 bytes)
 *
 * The published table uses 8-digit codes and a 30-second period.
 */
function rfcSecret(string $algo): string
{
    $seeds = [
        'sha1' => '12345678901234567890',
        'sha256' => '12345678901234567890123456789012',
        'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
    ];

    return TwoFactor::base32Encode($seeds[$algo]);
}

dataset('rfc6238', [
    // [timestamp, sha1, sha256, sha512]
    [59, '94287082', '46119246', '90693936'],
    [1111111109, '07081804', '68084774', '25091201'],
    [1111111111, '14050471', '67062674', '99943326'],
    [1234567890, '89005924', '91819424', '93441116'],
    [2000000000, '69279037', '90698825', '38618901'],
    [20000000000, '65353130', '77737706', '47863826'],
]);

test('RFC 6238 SHA1 vectors', function (int $ts, string $sha1, string $sha256, string $sha512) {
    expect(TwoFactor::code(rfcSecret('sha1'), $ts, 30, 8, 'sha1'))->toBe($sha1);
})->with('rfc6238');

test('RFC 6238 SHA256 vectors', function (int $ts, string $sha1, string $sha256, string $sha512) {
    expect(TwoFactor::code(rfcSecret('sha256'), $ts, 30, 8, 'sha256'))->toBe($sha256);
})->with('rfc6238');

test('RFC 6238 SHA512 vectors', function (int $ts, string $sha1, string $sha256, string $sha512) {
    expect(TwoFactor::code(rfcSecret('sha512'), $ts, 30, 8, 'sha512'))->toBe($sha512);
})->with('rfc6238');

test('base32 encode matches RFC 4648 known vectors', function (string $plain, string $encoded) {
    expect(TwoFactor::base32Encode($plain))->toBe($encoded);
})->with([
    ['', ''],
    ['f', 'MY'],
    ['fo', 'MZXQ'],
    ['foo', 'MZXW6'],
    ['foob', 'MZXW6YQ'],
    ['fooba', 'MZXW6YTB'],
    ['foobar', 'MZXW6YTBOI'],
]);

test('base32 decode round-trips arbitrary bytes', function () {
    $bytes = random_bytes(37);
    expect(TwoFactor::base32Decode(TwoFactor::base32Encode($bytes)))->toBe($bytes);
});

test('base32 decode tolerates lowercase and padding', function () {
    expect(TwoFactor::base32Decode('mzxw6ytboi'))->toBe('foobar')
        ->and(TwoFactor::base32Decode('MZXW6YTBOI======'))->toBe('foobar');
});

test('base32 decode rejects characters outside the alphabet', function () {
    TwoFactor::base32Decode('MZXW6YTB0I'); // contains a zero, not allowed
})->throws(InvalidArgumentException::class);

test('generateSecret returns uppercase base32 of the requested byte length', function () {
    $secret = TwoFactor::generateSecret(20);

    expect($secret)->toMatch('/^[A-Z2-7]+$/')
        ->and(strlen(TwoFactor::base32Decode($secret)))->toBe(20);
});

test('generateSecret produces unique values', function () {
    expect(TwoFactor::generateSecret())->not->toBe(TwoFactor::generateSecret());
});

test('code default arguments are sha1/6-digit/30s', function () {
    $secret = rfcSecret('sha1');
    // 6-digit truncation of the 8-digit RFC vector at t=59 → 287082
    expect(TwoFactor::code($secret, 59))->toBe('287082')
        ->and(strlen(TwoFactor::code($secret, 59)))->toBe(6);
});

test('verify accepts the current code', function () {
    $secret = TwoFactor::generateSecret();
    $code = TwoFactor::code($secret, 1000000000);

    expect(TwoFactor::verify($secret, $code, 1, 1000000000))->toBeTrue();
});

test('verify accepts codes within ±window drift', function () {
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;
    $prev = TwoFactor::code($secret, $now - 30);
    $next = TwoFactor::code($secret, $now + 30);

    expect(TwoFactor::verify($secret, $prev, 1, $now))->toBeTrue()
        ->and(TwoFactor::verify($secret, $next, 1, $now))->toBeTrue();
});

test('verify rejects codes outside the window', function () {
    $secret = TwoFactor::generateSecret();
    $now = 1000000000;
    $twoBack = TwoFactor::code($secret, $now - 60);

    expect(TwoFactor::verify($secret, $twoBack, 1, $now))->toBeFalse();
});

test('verify rejects a wrong code', function () {
    $secret = TwoFactor::generateSecret();

    expect(TwoFactor::verify($secret, '000000', 1, 1000000000))->toBeFalse();
});

test('verify rejects malformed (non-numeric / wrong length) input', function () {
    $secret = TwoFactor::generateSecret();

    expect(TwoFactor::verify($secret, 'abcdef', 1, 1000000000))->toBeFalse()
        ->and(TwoFactor::verify($secret, '12345', 1, 1000000000))->toBeFalse()
        ->and(TwoFactor::verify($secret, '', 1, 1000000000))->toBeFalse();
});

test('otpauthUri produces a standard provisioning URI', function () {
    $uri = TwoFactor::otpauthUri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'Acme Inc');

    expect($uri)->toStartWith('otpauth://totp/Acme%20Inc:alice%40example.com?')
        ->and($uri)->toContain('secret=JBSWY3DPEHPK3PXP')
        ->and($uri)->toContain('issuer=Acme%20Inc')
        ->and($uri)->toContain('algorithm=SHA1')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});

test('otpauthUri reflects non-default algorithm, digits and period', function () {
    $uri = TwoFactor::otpauthUri('JBSWY3DPEHPK3PXP', 'a@b.co', 'Iss', 60, 8, 'sha256');

    expect($uri)->toContain('algorithm=SHA256')
        ->and($uri)->toContain('digits=8')
        ->and($uri)->toContain('period=60');
});

test('generateRecoveryCodes returns formatted unique codes', function () {
    $codes = TwoFactor::generateRecoveryCodes(8);

    expect($codes)->toHaveCount(8)
        ->and(array_unique($codes))->toHaveCount(8);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[a-z0-9]+-[a-z0-9]+$/');
    }
});

test('hashRecoveryCode is deterministic and verifies', function () {
    $codes = TwoFactor::generateRecoveryCodes(3);
    $hashes = array_map([TwoFactor::class, 'hashRecoveryCode'], $codes);

    expect(TwoFactor::hashRecoveryCode($codes[0]))->toBe($hashes[0])
        ->and(TwoFactor::verifyRecoveryCode($codes[1], $hashes))->toBe(1)
        ->and(TwoFactor::verifyRecoveryCode($codes[2], $hashes))->toBe(2);
});

test('verifyRecoveryCode returns false for an unknown code', function () {
    $hashes = array_map([TwoFactor::class, 'hashRecoveryCode'], TwoFactor::generateRecoveryCodes(3));

    expect(TwoFactor::verifyRecoveryCode('zzzz-zzzz', $hashes))->toBeFalse();
});

test('verifyRecoveryCode index enables host-side single-use consumption', function () {
    $codes = TwoFactor::generateRecoveryCodes(4);
    $hashes = array_map([TwoFactor::class, 'hashRecoveryCode'], $codes);

    $index = TwoFactor::verifyRecoveryCode($codes[2], $hashes);
    expect($index)->toBe(2);

    // Host removes the consumed hash; the same code no longer matches.
    unset($hashes[$index]);
    expect(TwoFactor::verifyRecoveryCode($codes[2], array_values($hashes)))->toBeFalse();
});
