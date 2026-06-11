<?php

declare(strict_types=1);

use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Auth\EmailVerification;
use Ions\Auth\Http\EnsureEmailVerified;
use Ions\Auth\Notifications\VerifyEmail;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Support\Mail;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Email verification — middleware gating, resend throttle, notification,
| end-to-end through Kernel::handle (signed + verified interplay).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    bootFixtureKernel();
    // The framework VerifyEmailMail leaves the From to the host (MAIL_FROM_ADDRESS).
    putenv('MAIL_FROM_ADDRESS=noreply@example.test');
    $_ENV['MAIL_FROM_ADDRESS'] = 'noreply@example.test';
});

afterEach(function () {
    putenv('MAIL_FROM_ADDRESS');
    unset($_ENV['MAIL_FROM_ADDRESS']);
});

/**
 * A VerifiesEmail + ->email user, optionally pre-verified, suitable both as the
 * request's auth_user and as a notifiable (the mail channel routes ->email).
 */
function verifyFlowUser(bool $verified = false, string|int $id = 7, string $email = 'jane@example.test'): VerifiesEmail
{
    return new class ($verified, $id, $email) implements VerifiesEmail {
        public ?DateTimeInterface $verifiedAt;

        public function __construct(bool $verified, private string|int $id, public string $email)
        {
            $this->verifiedAt = $verified ? new DateTimeImmutable() : null;
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
            $this->verifiedAt = new DateTimeImmutable();
        }

        public function getEmailVerifiedAt(): ?DateTimeInterface
        {
            return $this->verifiedAt;
        }
    };
}

// --------------------------------------------------------------------------
// EnsureEmailVerified middleware
// --------------------------------------------------------------------------

test('EnsureEmailVerified lets a verified user through', function () {
    $request = Request::create('/dashboard');
    $request->attributes->set('auth_user', verifyFlowUser(verified: true));

    $response = (new EnsureEmailVerified())->handle($request, fn () => new Response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});

test('EnsureEmailVerified passes through when no auth user is present (opt-in)', function () {
    $request = Request::create('/dashboard'); // no auth_user

    $response = (new EnsureEmailVerified())->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

test('EnsureEmailVerified passes a user not implementing VerifiesEmail (opt-in)', function () {
    $request = Request::create('/dashboard');
    $request->attributes->set('auth_user', new stdClass()); // does not implement VerifiesEmail

    $response = (new EnsureEmailVerified())->handle($request, fn () => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

test('EnsureEmailVerified aborts 403 for an unverified user on an api request', function () {
    $request = Request::create('/api/dashboard');
    $request->attributes->set('auth_user', verifyFlowUser(verified: false));

    // abort(403) throws an HttpException the kernel renders as JSON for /api.
    expect(fn () => (new EnsureEmailVerified())->handle($request, fn () => new Response('ok')))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('EnsureEmailVerified redirects an unverified user on a web request', function () {
    config(['app.auth.email_verification_redirect' => '/verify-email']);

    $request = Request::create('/dashboard'); // web (not /api, no JSON)
    $request->attributes->set('auth_user', verifyFlowUser(verified: false));

    $response = (new EnsureEmailVerified())->handle($request, fn () => new Response('ok'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('/verify-email');
});

test("EnsureEmailVerified resolves through the 'verified' alias", function () {
    config(['app.middleware_aliases.verified' => EnsureEmailVerified::class]);

    $mw = Kernel::resolveMiddleware('verified');

    expect($mw)->toBeInstanceOf(EnsureEmailVerified::class);
});

// --------------------------------------------------------------------------
// VerifyEmail notification builds a mail carrying the signed link
// --------------------------------------------------------------------------

test('VerifyEmail notification sends a mail whose body carries the signed verification link', function () {
    Route::get('/email/verify', fn () => 'ok', [], 'verification.verify');
    Mail::fake();

    $user = verifyFlowUser(verified: false);
    notify($user, new VerifyEmail());

    Mail::assertSent(\Ions\Auth\Notifications\VerifyEmailMail::class, function ($mail) {
        $email = $mail->toSymfonyEmail();
        $body = (string) $email->getHtmlBody() . (string) $email->getTextBody();

        return str_contains($body, 'signature=') && str_contains($body, '/email/verify');
    });
});

// --------------------------------------------------------------------------
// Resend throttle (reuses the per-email limiter)
// --------------------------------------------------------------------------

test('sendVerification throttles a resend after the configured max per email', function () {
    config(['app.auth.verify_throttle' => ['max' => 2, 'decay' => 600]]);
    Route::get('/email/verify', fn () => 'ok', [], 'verification.verify');
    Mail::fake();

    $user = verifyFlowUser(verified: false);

    expect(EmailVerification::sendVerification($user))->toBeTrue()
        ->and(EmailVerification::sendVerification($user))->toBeTrue()
        // 3rd within the window is throttled.
        ->and(EmailVerification::sendVerification($user))->toBeFalse();

    Mail::assertSentCount(2);
});

// --------------------------------------------------------------------------
// End-to-end through Kernel::handle — signed route + verify
// --------------------------------------------------------------------------

test('a signed verify route verifies the user end-to-end through the kernel', function () {
    $user = verifyFlowUser(verified: false);

    // The host controller calls EmailVerification::verify($request, $user).
    // We close over the user; the route is protected by the 'signed' alias so
    // a tampered/expired link is rejected before the controller even runs.
    Route::get('/email/verify', function (Request $request) use ($user) {
        return new Response(EmailVerification::verify($request, $user) ? 'verified' : 'failed');
    }, [], 'verification.verify')->middleware(['signed']);

    $url = EmailVerification::verificationUrl($user);
    $uri = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    $response = Kernel::handle(Request::create($uri));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('verified')
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('the signed middleware rejects a tampered verify link before the controller runs', function () {
    $user = verifyFlowUser(verified: false);

    Route::get('/email/verify', function (Request $request) use ($user) {
        return new Response(EmailVerification::verify($request, $user) ? 'verified' : 'failed');
    }, [], 'verification.verify')->middleware(['signed']);

    $url = EmailVerification::verificationUrl($user);
    $tampered = preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $url);
    $uri = parse_url((string) $tampered, PHP_URL_PATH) . '?' . parse_url((string) $tampered, PHP_URL_QUERY);

    $response = Kernel::handle(Request::create($uri));

    expect($response->getStatusCode())->toBe(403)
        ->and($user->hasVerifiedEmail())->toBeFalse();
});
