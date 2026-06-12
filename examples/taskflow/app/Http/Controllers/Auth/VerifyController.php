<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Ions\Auth\EmailVerification;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;

/**
 * Target of the signed verification link (route name 'verification.verify', the
 * default EmailVerification::verificationUrl() expects). Loads the user by the
 * link's `id`, then EmailVerification::verify() re-checks the signature/expiry
 * and binds the link's hash to the user's CURRENT email before flipping
 * email_verified_at. The 'signed' middleware on the route rejects a tampered
 * link up front; verify() is the defensive second check.
 */
final class VerifyController extends BaseController
{
    public function verify(Request $request): RedirectResponse
    {
        $user = User::query()->find($request->query->get('id'));

        if ($user === null || !EmailVerification::verify($request, $user)) {
            return redirect('/login')->with(
                'status',
                'That verification link is invalid or has expired.'
            );
        }

        return redirect('/login')->with(
            'status',
            'Email verified — you can now sign in.'
        );
    }
}
