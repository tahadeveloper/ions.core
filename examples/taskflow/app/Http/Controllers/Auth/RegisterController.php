<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\RegisterRequest;
use App\Mail\WelcomeMail;
use App\Models\User;
use Ions\Auth\EmailVerification;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Registration: show the form, then create an UNVERIFIED user and mail a signed
 * verification link (Ions\Auth\EmailVerification → the 'verification.verify'
 * signed route). The new user is not logged in until they verify + sign in.
 */
final class RegisterController extends BaseController
{
    public function create(Request $request): View
    {
        return view('auth/register', [
            'status' => flash('status'),
            'error' => flash('error'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = RegisterRequest::validate($request);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash((string) $data['password'], PASSWORD_BCRYPT),
            // Left unverified: email_verified_at stays null until the link is used.
        ]);

        // Mails a signed, expiring link (throttled per-email). Notification::fake
        // records this in tests; a real run sends it through the mail channel.
        EmailVerification::sendVerification($user);

        // Welcome email (13.5) — Mail::fake records this in tests.
        (new WelcomeMail((string) $user->email, (string) $user->name))->send();

        return redirect('/login')->with(
            'status',
            'Account created — check your email for a verification link.'
        );
    }
}
