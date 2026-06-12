<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Web session login. On valid credentials: if the user has 2FA enabled, stash a
 * PENDING id in the session and bounce to the TOTP challenge; otherwise log them
 * straight in (session('auth_user_id')). The session id is regenerated on a full
 * login to harden against fixation.
 */
final class LoginController extends BaseController
{
    public function create(Request $request): View
    {
        return view('auth/login', [
            'status' => flash('status'),
            'error' => flash('error'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = LoginRequest::validate($request);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || !password_verify((string) $data['password'], (string) $user->password)) {
            return back('/login')->with('error', 'Invalid credentials.');
        }

        // 2FA enabled: defer the real login until the TOTP challenge passes.
        if ($user->two_factor_secret !== null) {
            session()->put('2fa.pending_id', $user->getKey());

            return redirect('/login/2fa');
        }

        $this->login($user);

        return redirect('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        session()->forget('auth_user_id');
        session()->regenerate(true);

        return redirect('/login')->with('status', 'Signed out.');
    }

    /** Establish the authenticated web session for $user. */
    private function login(User $user): void
    {
        session()->regenerate();
        session()->put('auth_user_id', $user->getKey());
        session()->forget('2fa.pending_id');
    }
}
