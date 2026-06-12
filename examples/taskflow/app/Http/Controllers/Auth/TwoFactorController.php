<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Ions\Auth\TwoFactor;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Security\DecryptException;
use Ions\Security\Encrypter;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Two-factor (TOTP) enrolment + login challenge.
 *
 * Enrolment: generate a secret, present its otpauth:// URI (the host renders a
 * QR; here it is shown as a link) plus one-time recovery codes, and require the
 * user to confirm a fresh code before the secret is committed. The secret is
 * stored ENCRYPTED at rest (Ions\Security\Encrypter); recovery codes are stored
 * hashed (TwoFactor::hashRecoveryCode). The pending secret/codes live in the
 * session until confirmation.
 *
 * Challenge: during login, verify the submitted TOTP against the decrypted
 * secret (TwoFactor::verify with a ±1 step window) to complete the session.
 */
final class TwoFactorController extends BaseController
{
    private const ISSUER = 'Taskflow';

    /** Show the enrolment screen with a fresh secret + recovery codes. */
    public function enable(Request $request): View|RedirectResponse
    {
        $user = $this->currentUser();
        if ($user === null) {
            return redirect('/login');
        }

        $secret = TwoFactor::generateSecret();
        $recovery = TwoFactor::generateRecoveryCodes();

        // Hold the candidates in the session until the user proves a code works.
        session()->put('2fa.setup_secret', $secret);
        session()->put('2fa.setup_recovery', $recovery);

        return view('auth/2fa-enable', [
            'otpauth_uri' => TwoFactor::otpauthUri($secret, (string) $user->email, self::ISSUER),
            'secret' => $secret,
            'recovery_codes' => $recovery,
            'error' => flash('error'),
        ]);
    }

    /** Confirm enrolment: a valid TOTP commits the encrypted secret + hashes. */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        if ($user === null) {
            return redirect('/login');
        }

        $secret = (string) session('2fa.setup_secret');
        /** @var list<string> $recovery */
        $recovery = (array) session('2fa.setup_recovery', []);
        $code = (string) $request->request->get('code', '');

        if ($secret === '' || !TwoFactor::verify($secret, $code)) {
            return back('/2fa')->with('error', 'That code is not valid — try again.');
        }

        $user->forceFill([
            'two_factor_secret' => $this->encrypter()->encrypt($secret),
            'two_factor_recovery_codes' => json_encode(
                array_map(static fn (string $c): string => TwoFactor::hashRecoveryCode($c), $recovery),
                JSON_THROW_ON_ERROR
            ),
        ])->save();

        session()->forget('2fa.setup_secret');
        session()->forget('2fa.setup_recovery');

        return redirect('/dashboard')->with('status', 'Two-factor authentication enabled.');
    }

    /** Show the login TOTP challenge (after a password check on a 2FA user). */
    public function challenge(Request $request): View|RedirectResponse
    {
        if (session('2fa.pending_id') === null) {
            return redirect('/login');
        }

        return view('auth/2fa-challenge', ['error' => flash('error')]);
    }

    /** Verify the login TOTP and complete the deferred web session login. */
    public function verify(Request $request): RedirectResponse
    {
        $pendingId = session('2fa.pending_id');
        if ($pendingId === null) {
            return redirect('/login');
        }

        $user = User::query()->find($pendingId);
        if ($user === null || $user->two_factor_secret === null) {
            session()->forget('2fa.pending_id');

            return redirect('/login');
        }

        $code = (string) $request->request->get('code', '');

        try {
            $secret = $this->encrypter()->decrypt((string) $user->two_factor_secret);
        } catch (DecryptException) {
            return redirect('/login')->with('error', 'Could not verify two-factor — sign in again.');
        }

        // Replay protection: verifyOnce() rejects a code whose time-step was
        // already consumed for this user, so a captured OTP can't be replayed
        // within its ±1-step acceptance window. This is the production-correct
        // path on the login challenge (vs. plain TwoFactor::verify()).
        $replay = new \Ions\Auth\TwoFactorReplayStore(app('cache.store'));
        if (!$replay->verifyOnce((string) $user->getKey(), $secret, $code)) {
            return back('/login/2fa')->with('error', 'That code is not valid — try again.');
        }

        session()->regenerate();
        session()->put('auth_user_id', $user->getKey());
        session()->forget('2fa.pending_id');

        return redirect('/dashboard');
    }

    /** Turn 2FA off for the current user. */
    public function disable(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        if ($user === null) {
            return redirect('/login');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return redirect('/dashboard')->with('status', 'Two-factor authentication disabled.');
    }

    /** The logged-in user from the web session, or null. */
    private function currentUser(): ?User
    {
        $id = session('auth_user_id');

        return $id === null ? null : User::query()->find($id);
    }

    private function encrypter(): Encrypter
    {
        /** @var Encrypter $encrypter */
        $encrypter = app('encrypter');

        return $encrypter;
    }
}
