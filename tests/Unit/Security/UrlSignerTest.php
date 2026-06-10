<?php

declare(strict_types=1);

use Ions\Security\UrlSigner;

const SIGNER_APP_KEY = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

test('sign appends a signature param and verify accepts it', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/download/42?disk=local');

    expect($signed)->toContain('signature=')
        ->and($signer->verify($signed))->toBeTrue();
});

test('absolute URLs sign and verify (scheme/host preserved, excluded from the HMAC)', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('https://example.test/unsubscribe?user=7');

    expect($signed)->toStartWith('https://example.test/unsubscribe?')
        ->and($signer->verify($signed))->toBeTrue()
        // Host is not part of the canonical string: the same path+query verifies relative.
        ->and($signer->verify(substr($signed, strlen('https://example.test'))))->toBeTrue();
});

test('verification is independent of query parameter order', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/report?b=2&a=1&c=3');

    // Manually reorder the query string.
    [$path, $query] = explode('?', $signed, 2);
    parse_str($query, $params);
    krsort($params);
    $reordered = $path . '?' . http_build_query($params);

    expect($reordered)->not->toBe($signed)
        ->and($signer->verify($reordered))->toBeTrue();
});

test('a tampered query parameter fails verification', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/download/42?user=7');

    expect($signer->verify(str_replace('user=7', 'user=8', $signed)))->toBeFalse();
});

test('a tampered signature fails verification', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/download/42');
    $tampered = preg_replace('/signature=\w{4}/', 'signature=ffff', $signed);

    expect($signer->verify((string) $tampered))->toBeFalse();
});

test('a URL without a signature fails verification', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    expect($signer->verify('/download/42?user=7'))->toBeFalse()
        ->and($signer->verify('/download/42'))->toBeFalse();
});

test('a future expiry verifies; a past expiry fails', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $future = $signer->sign('/reset', time() + 3600);
    $past = $signer->sign('/reset', time() - 10);

    expect($future)->toContain('expires=')
        ->and($signer->verify($future))->toBeTrue()
        ->and($signer->verify($past))->toBeFalse();
});

test('expiry accepts a DateTimeInterface', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/reset', new DateTimeImmutable('+1 hour'));

    expect($signer->verify($signed))->toBeTrue();
});

test('a stripped or tampered expires param fails verification', function () {
    $signer = UrlSigner::fromAppKey(SIGNER_APP_KEY);

    $signed = $signer->sign('/reset', time() + 3600);

    // Pushing the expiry forward must break the HMAC.
    $extended = preg_replace('/expires=\d+/', 'expires=' . (time() + 999999), $signed);

    expect($signer->verify((string) $extended))->toBeFalse();
});

test('a signature from a different key fails verification', function () {
    $signed = UrlSigner::fromAppKey(SIGNER_APP_KEY)->sign('/download/42');
    $other = UrlSigner::fromAppKey(str_repeat('q', 64));

    expect($other->verify($signed))->toBeFalse();
});

test('signer and encrypter derive distinct keys from the same APP_KEY', function () {
    $canonical = UrlSigner::fromAppKey(SIGNER_APP_KEY);
    $encrypterDerived = new UrlSigner(hash_hkdf('sha256', SIGNER_APP_KEY, 32, 'ions.encrypter.v1'));

    expect($encrypterDerived->verify($canonical->sign('/x')))->toBeFalse();
});

test('fromAppKey rejects keys shorter than 32 bytes with a RuntimeException naming APP_KEY', function () {
    expect(fn () => UrlSigner::fromAppKey('short'))
        ->toThrow(RuntimeException::class, 'APP_KEY');
});
