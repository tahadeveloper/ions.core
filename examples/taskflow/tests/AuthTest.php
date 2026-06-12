<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow auth surface (13.3)
|--------------------------------------------------------------------------
| Exercises the register → verify → optional 2FA → login journey plus the
| JWT API login and the Gate/policies, all on the throwaway in-memory SQLite
| connection (migrateTaskflow). CSRF is disabled for the web POSTs (the same
| pattern the framework's own form-flow tests use); the kernel's array session
| persists across sequential handle() calls in one process, so a login set in
| one request is visible to the next.
*/

use App\Models\Project;
use App\Models\User;
use Ions\Auth\EmailVerification;
use Ions\Auth\Notifications\VerifyEmail;
use Ions\Auth\TwoFactor;
use Ions\Security\Encrypter;
use Ions\Support\Mail;
use Ions\Support\Notifications;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    // The verification resend throttle counts hits in the persistent (file)
    // cache, which survives across test runs; flush it so each run starts clean
    // and EmailVerification::sendVerification() is never spuriously throttled.
    cache()->flush();
});

// --- Registration + email verification --------------------------------------

test('register creates an unverified user and sends a verification notification', function () {
    $fake = Notifications::fake();
    Mail::fake(); // registration also sends a WelcomeMail (13.5)

    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertRedirect('http://localhost:8000/login');

    $user = User::query()->where('email', 'ada@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $fake->assertSentTo($user, VerifyEmail::class);
});

test('the signed verification link flips email_verified_at', function () {
    $user = User::factory()->unverified()->create();

    $url = EmailVerification::verificationUrl($user);
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    $this->get($path)->assertRedirect('http://localhost:8000/login');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a tampered verification link does not verify the user', function () {
    $user = User::factory()->unverified()->create();

    $url = EmailVerification::verificationUrl($user);
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    // Flip the signature: the 'signed' middleware rejects it (403).
    $this->get($path . 'tampered')->assertStatus(403);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test("the 'verified' middleware blocks an unverified web user then allows a verified one", function () {
    $user = User::factory()->unverified()->create();
    session()->put('auth_user_id', $user->getKey());

    // Unverified: EnsureEmailVerified redirects to the configured page.
    $this->get('/dashboard')->assertRedirect('http://localhost:8000/email/verify');

    $user->markEmailVerified();

    $this->get('/dashboard')->assertOk();
});

// --- Two-factor (TOTP) ------------------------------------------------------

test('enabling 2FA stores an encrypted secret + hashed recovery codes that a valid TOTP confirms', function () {
    $user = User::factory()->create(); // verified by default
    session()->put('auth_user_id', $user->getKey());

    // Step 1: open the enrolment screen — a secret is generated into the session.
    $this->get('/2fa')->assertOk();
    $secret = (string) session('2fa.setup_secret');
    expect($secret)->not->toBe('');

    // Step 2: confirm with a code computed from that secret.
    $this->post('/2fa', ['code' => TwoFactor::code($secret)])
        ->assertRedirect('http://localhost:8000/dashboard');

    $user = $user->fresh();
    expect($user->two_factor_secret)->not->toBeNull()
        // Stored ENCRYPTED, not in clear: the payload is the iev1: envelope.
        ->and($user->two_factor_secret)->toStartWith('iev1:')
        ->and($user->two_factor_secret)->not->toBe($secret);

    // The encrypted secret round-trips back to the original.
    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    expect($encrypter->decrypt((string) $user->two_factor_secret))->toBe($secret);

    // Recovery codes are stored hashed (sha256 hex digests), not the raw codes.
    $codes = json_decode((string) $user->two_factor_recovery_codes, true);
    expect($codes)->toBeArray()->toHaveCount(8)
        ->and(strlen((string) $codes[0]))->toBe(64)
        ->and($codes[0])->toMatch('/^[0-9a-f]{64}$/');
});

test('login requires the TOTP challenge when 2FA is enabled', function () {
    $secret = TwoFactor::generateSecret();
    $user = User::factory()->create();
    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    $user->forceFill(['two_factor_secret' => $encrypter->encrypt($secret)])->save();

    // Correct password but 2FA on → bounced to the challenge, not logged in yet.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost:8000/login/2fa');
    expect(session('auth_user_id'))->toBeNull()
        ->and(session('2fa.pending_id'))->toBe($user->getKey());

    // Wrong code is rejected; a valid code completes the login.
    $this->post('/login/2fa', ['code' => '000000'])->assertRedirect('http://localhost:8000/login/2fa');
    expect(session('auth_user_id'))->toBeNull();

    $this->post('/login/2fa', ['code' => TwoFactor::code($secret)])
        ->assertRedirect('http://localhost:8000/dashboard');
    expect(session('auth_user_id'))->toBe($user->getKey());
});

test('a TOTP code consumed once cannot be replayed within its window', function () {
    $secret = TwoFactor::generateSecret();
    $user = User::factory()->create();
    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    $user->forceFill(['two_factor_secret' => $encrypter->encrypt($secret)])->save();

    $code = TwoFactor::code($secret);

    // First use completes the login.
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/login/2fa', ['code' => $code])
        ->assertRedirect('http://localhost:8000/dashboard');
    expect(session('auth_user_id'))->toBe($user->getKey());

    // Log out, then replay the SAME code — the replay store rejects it.
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/login/2fa', ['code' => $code])
        ->assertRedirect('http://localhost:8000/login/2fa');
    expect(session('auth_user_id'))->toBeNull();
});

// --- Web session login (no 2FA) ---------------------------------------------

test('login with the correct password sets the web session and reaches the dashboard', function () {
    $user = User::factory()->create(); // verified, no 2FA

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost:8000/dashboard');

    expect(session('auth_user_id'))->toBe($user->getKey());
    $this->get('/dashboard')->assertOk();
});

test('login with a wrong password redirects back without a session', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'nope'], [
        'HTTP_REFERER' => 'http://localhost:8000/login',
    ])->assertRedirect();

    expect(session('auth_user_id'))->toBeNull();
});

// --- JWT API login + protected route ----------------------------------------

test('the JSON API login returns a JWT token', function () {
    $user = User::factory()->create();

    $response = $this->json('POST', '/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk();
    expect($response->json('data.access_token'))->toBeString()->not->toBe('')
        ->and($response->json('data.token_type'))->toBe('Bearer');
});

test('the API login rejects wrong credentials', function () {
    $user = User::factory()->create();

    $this->json('POST', '/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ])->assertStatus(401);
});

test('actingAs reaches a protected API route the policy allows', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $this->actingAs($owner)
        ->get('/api/projects/' . $project->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $project->getKey());
});

// --- Policies ---------------------------------------------------------------

test('ProjectPolicy denies a non-member and allows the owner + members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    $project->members()->attach($member->getKey());

    /** @var \Ions\Auth\Gate $gate */
    $gate = app('gate');

    expect($gate->forUser($owner)->allows('view', $project))->toBeTrue()
        ->and($gate->forUser($member)->allows('view', $project))->toBeTrue()
        ->and($gate->forUser($outsider)->allows('view', $project))->toBeFalse()
        // Delete is owner-only.
        ->and($gate->forUser($owner)->allows('delete', $project))->toBeTrue()
        ->and($gate->forUser($member)->allows('delete', $project))->toBeFalse();
});

test('the protected API route 403s a non-member', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $this->actingAs($outsider)
        ->get('/api/projects/' . $project->getKey())
        ->assertStatus(403);
});

test('the create-project ability requires a verified user', function () {
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();

    /** @var \Ions\Auth\Gate $gate */
    $gate = app('gate');

    expect($gate->forUser($verified)->allows('create-project'))->toBeTrue()
        ->and($gate->forUser($unverified)->allows('create-project'))->toBeFalse();
});
