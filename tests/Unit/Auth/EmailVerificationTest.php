<?php

declare(strict_types=1);

use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Auth\EmailVerification;
use Ions\Foundation\Kernel;
use Ions\Security\UrlSigner;
use Ions\Support\Request;

/**
 * EmailVerification — signed links bound to the user's CURRENT email.
 *
 * The verifier needs no database: the host owns persistence. These tests use an
 * in-memory VerifiesEmail fixture whose markEmailVerified() flips a flag. The
 * fixture kernel binds 'url.signer' (SecurityProvider) so signedRoute()/verify()
 * share one HMAC key.
 */
beforeEach(function () {
    bootFixtureKernel();
    // A named route the verification URL points at; signedRoute() resolves it.
    // id + hash ride as signed query params (no path placeholders), so verify()
    // reads them from the request query independent of routing.
    \Ions\Bundles\Route::get('/email/verify', fn () => 'ok', [], 'verification.verify');
});

/**
 * In-memory VerifiesEmail user. markEmailVerified() records the timestamp so
 * tests can assert verify() flipped it.
 */
function verifiableUser(string|int $id = 7, string $email = 'jane@example.test'): VerifiesEmail
{
    return new class ($id, $email) implements VerifiesEmail {
        public ?DateTimeInterface $verifiedAt = null;
        public int $markCalls = 0;

        public function __construct(private string|int $id, private string $email)
        {
        }

        public function getEmailForVerification(): string
        {
            return $this->email;
        }

        public function getKeyForVerification(): string|int
        {
            return $this->id;
        }

        public function hasVerifiedEmail(): bool
        {
            return $this->verifiedAt !== null;
        }

        public function markEmailVerified(): void
        {
            $this->markCalls++;
            $this->verifiedAt = new DateTimeImmutable();
        }

        public function getEmailVerifiedAt(): ?DateTimeInterface
        {
            return $this->verifiedAt;
        }
    };
}

/** Build a Request whose URI is the path+query of a (possibly absolute) URL. */
function requestForUrl(string $url): Request
{
    $parts = parse_url($url);
    $uri = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    return Request::create($uri);
}

test('verificationUrl produces a signed URL carrying id, the email hash, expires and a signature', function () {
    $user = verifiableUser(7, 'jane@example.test');

    $url = EmailVerification::verificationUrl($user);

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('signature')
        ->and($query)->toHaveKey(UrlSigner::EXPIRES_PARAM)
        ->and($query['id'])->toBe('7')
        ->and($query['hash'])->toBe(hash('sha256', 'jane@example.test'))
        // The URL must verify against the shared signer.
        ->and(Kernel::app()->make('url.signer')->verify($url))->toBeTrue();
});

test('verify() returns true and flips markEmailVerified for a valid link', function () {
    $user = verifiableUser(7, 'jane@example.test');
    $url = EmailVerification::verificationUrl($user);

    expect($user->hasVerifiedEmail())->toBeFalse();

    $ok = EmailVerification::verify(requestForUrl($url), $user);

    expect($ok)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->markCalls)->toBe(1)
        ->and($user->getEmailVerifiedAt())->toBeInstanceOf(DateTimeInterface::class);
});

test('verify() is idempotent: an already-verified user returns true without re-marking', function () {
    $user = verifiableUser(7, 'jane@example.test');
    $user->markEmailVerified(); // pre-verified
    $url = EmailVerification::verificationUrl($user);

    $ok = EmailVerification::verify(requestForUrl($url), $user);

    expect($ok)->toBeTrue()
        ->and($user->markCalls)->toBe(1); // not marked a second time
});

test('verify() returns false when the email changed (hash no longer binds) and does not mark', function () {
    $user = verifiableUser(7, 'old@example.test');
    $url = EmailVerification::verificationUrl($user); // hash over OLD email

    // Simulate the user changing their email before clicking the link.
    $changed = verifiableUser(7, 'new@example.test');

    $ok = EmailVerification::verify(requestForUrl($url), $changed);

    expect($ok)->toBeFalse()
        ->and($changed->hasVerifiedEmail())->toBeFalse();
});

test('verify() returns false when the id does not match the user key', function () {
    $user = verifiableUser(7, 'jane@example.test');
    $url = EmailVerification::verificationUrl($user);

    // A different user with a valid-looking (but wrong-id) link.
    $other = verifiableUser(99, 'jane@example.test');

    $ok = EmailVerification::verify(requestForUrl($url), $other);

    expect($ok)->toBeFalse()
        ->and($other->hasVerifiedEmail())->toBeFalse();
});

test('verify() returns false for an expired link (signature/expiry re-checked defensively)', function () {
    $user = verifiableUser(7, 'jane@example.test');
    // Expire 1 minute in the past.
    $url = EmailVerification::verificationUrl($user, 'verification.verify', -1);

    $ok = EmailVerification::verify(requestForUrl($url), $user);

    expect($ok)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();
});

test('verify() returns false when the signature is tampered', function () {
    $user = verifiableUser(7, 'jane@example.test');
    $url = EmailVerification::verificationUrl($user);

    // Flip the signature value.
    $tampered = preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $url);

    $ok = EmailVerification::verify(requestForUrl((string) $tampered), $user);

    expect($ok)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();
});
