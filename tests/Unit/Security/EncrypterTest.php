<?php

declare(strict_types=1);

use Ions\Security\DecryptException;
use Ions\Security\Encrypter;

const APP_KEY_FIXTURE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

test('encrypt/decrypt round-trips a plaintext', function () {
    $encrypter = Encrypter::fromAppKey(APP_KEY_FIXTURE);

    $payload = $encrypter->encrypt('secret value with unicode — héllo');

    expect($payload)->toStartWith('iev1:')
        ->and($encrypter->decrypt($payload))->toBe('secret value with unicode — héllo');
});

test('encrypting the same plaintext twice yields different payloads (random nonce)', function () {
    $encrypter = Encrypter::fromAppKey(APP_KEY_FIXTURE);

    expect($encrypter->encrypt('same'))->not->toBe($encrypter->encrypt('same'));
});

test('a tampered payload throws DecryptException', function () {
    $encrypter = Encrypter::fromAppKey(APP_KEY_FIXTURE);
    $payload = $encrypter->encrypt('tamper me');

    // Decode the body, flip one byte in the ciphertext, re-encode.
    $body = substr($payload, strlen('iev1:'));
    $raw = base64_decode(strtr($body, '-_', '+/'), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
    $tampered = 'iev1:' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    expect(fn () => $encrypter->decrypt($tampered))->toThrow(DecryptException::class);
});

test('decrypting with a different key throws DecryptException', function () {
    $payload = Encrypter::fromAppKey(APP_KEY_FIXTURE)->encrypt('cross-key');
    $other = Encrypter::fromAppKey(str_repeat('zz', 32));

    expect(fn () => $other->decrypt($payload))->toThrow(DecryptException::class);
});

test('garbage, empty, and bad-prefix payloads throw DecryptException', function (string $garbage) {
    $encrypter = Encrypter::fromAppKey(APP_KEY_FIXTURE);

    expect(fn () => $encrypter->decrypt($garbage))->toThrow(DecryptException::class);
})->with([
    'empty' => [''],
    'garbage' => ['not-an-encrypted-payload'],
    'bad prefix' => ['xxv9:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
    'prefix only' => ['iev1:'],
    'invalid base64' => ['iev1:%%%not-base64%%%'],
    'too short body' => ['iev1:' . rtrim(strtr(base64_encode('short'), '+/', '-_'), '=')],
]);

test('encrypter and url-signer keys derived from the same APP_KEY are distinct (HKDF info strings)', function () {
    // Derive a key with the UrlSigner's info string; an Encrypter built on it
    // must NOT decrypt payloads from the canonical encrypter derivation.
    $signerDerived = hash_hkdf('sha256', APP_KEY_FIXTURE, 32, 'ions.urlsigner.v1');
    $payload = Encrypter::fromAppKey(APP_KEY_FIXTURE)->encrypt('domain separation');

    expect(fn () => (new Encrypter($signerDerived))->decrypt($payload))
        ->toThrow(DecryptException::class);
});

test('fromAppKey rejects keys shorter than 32 bytes with a RuntimeException naming APP_KEY', function () {
    expect(fn () => Encrypter::fromAppKey('too-short'))
        ->toThrow(RuntimeException::class, 'APP_KEY');
});

test('the constructor rejects raw keys that are not exactly 32 bytes', function () {
    expect(fn () => new Encrypter('short'))->toThrow(InvalidArgumentException::class);
});
