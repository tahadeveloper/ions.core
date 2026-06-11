<?php

declare(strict_types=1);

namespace Ions\Auth;

use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Auth\Notifications\VerifyEmail;
use Ions\Foundation\Kernel;
use Ions\Security\UrlSigner;
use Ions\Support\Request;

/**
 * Email verification built on signed URLs (8.5) + Notifications (8.5).
 *
 * Flow: register → {@see sendVerification()} mails a signed, expiring link →
 * the user clicks → the host controller calls {@see verify()} → the user is
 * marked verified. The {@see Http\EnsureEmailVerified} middleware gates routes
 * behind verification.
 *
 * Security: the link carries `id` (the user key) + `hash` (sha256 of the user's
 * email at issue time) + `expires`, all covered by the UrlSigner HMAC. verify()
 * re-checks the signature/expiry defensively (even when the 'signed' middleware
 * already validated it), then constant-time compares the link's `hash` against
 * sha256 of the user's CURRENT email — so changing the email invalidates the
 * link. The verifier needs no database; the host owns `email_verified_at`.
 */
final class EmailVerification
{
    /**
     * Build a signed, expiring verification URL for $user.
     *
     * The signed route receives `['id' => key, 'hash' => sha256(email)]`; the
     * hash binds the link to the email it was issued for.
     *
     * @param string $routeName  The named verify route (must accept {id} and {hash}).
     * @param int    $expiresMinutes Link lifetime in minutes (may be negative in tests).
     */
    public static function verificationUrl(
        VerifiesEmail $user,
        string $routeName = 'verification.verify',
        int $expiresMinutes = 60,
    ): string {
        return signedRoute(
            $routeName,
            [
                'id'   => $user->getKeyForVerification(),
                'hash' => hash('sha256', $user->getEmailForVerification()),
            ],
            time() + $expiresMinutes * 60,
        );
    }

    /**
     * Validate the verification request for $user and mark them verified.
     *
     * Contract: returns true and calls {@see VerifiesEmail::markEmailVerified()}
     * on success (idempotent — an already-verified user returns true WITHOUT
     * re-marking). Returns false — and never marks — when the signature/expiry,
     * the id, or the email hash do not match.
     *
     * Checks, in order:
     *   1. The signed URL signature + expiry (re-checked via {@see UrlSigner}
     *      over the request URI, defensively — the 'signed' middleware may have
     *      already validated it).
     *   2. The route `id` equals the user's key.
     *   3. The route `hash` equals sha256 of the user's CURRENT email, compared
     *      with hash_equals() (timing-safe; binds the link to the live address).
     */
    public static function verify(Request $request, VerifiesEmail $user): bool
    {
        // 1. Signature + expiry — fail closed on a missing/short key.
        if (!self::signer()->verify($request->getRequestUri())) {
            return false;
        }

        // 2. id must match the user key. id + hash ride as signed query params
        //    (verificationUrl() never embeds them as path placeholders).
        $id = (string) $request->query->get('id', '');
        if ($id !== (string) $user->getKeyForVerification()) {
            return false;
        }

        // 3. hash must bind to the user's CURRENT email (timing-safe).
        $hash = (string) $request->query->get('hash', '');
        $expected = hash('sha256', $user->getEmailForVerification());
        if (!hash_equals($expected, $hash)) {
            return false;
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailVerified();
        }

        return true;
    }

    /**
     * Mail $user a fresh verification link, subject to a per-email resend
     * throttle (config app.auth.verify_throttle = ['max' => N, 'decay' => secs]).
     *
     * Returns true when the notification was dispatched, false when throttled.
     * Already-verified users are a no-op returning true (nothing to resend).
     */
    public static function sendVerification(VerifiesEmail $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        if (self::resendThrottled($user->getEmailForVerification())) {
            return false;
        }

        notify($user, new VerifyEmail());

        return true;
    }

    /**
     * Per-email resend throttle backed by the shared cache, mirroring the
     * forgot-password limiter (12.4 reuse). Returns true when the caller should
     * be throttled. Best-effort: a missing cache binding never blocks resends.
     */
    private static function resendThrottled(string $email): bool
    {
        try {
            $app = Kernel::app();
            if (!$app->has('cache')) {
                return false;
            }

            /** @var array<string,mixed> $config */
            $config = (array) config('app.auth.verify_throttle', []);
            $max = (int) ($config['max'] ?? 3);
            $decay = (int) ($config['decay'] ?? 600);

            /** @var \Illuminate\Cache\CacheManager $manager */
            $manager = $app->get('cache');
            $cache = $manager->store();

            // trim()+lower(): collapse pad-space / case-variant addresses to one key.
            $key = 'verify:' . sha1(strtolower(trim($email)));

            // add() establishes the key with the window TTL; increment() then
            // only ever runs on an existing (TTL-bearing) key.
            $cache->add($key, 0, $decay);
            $hits = (int) $cache->increment($key);

            return $hits > $max;
        } catch (\Throwable) {
            // Never let throttling internals block verification email delivery.
            return false;
        }
    }

    /**
     * Resolve the UrlSigner: prefer the container 'url.signer' (SecurityProvider),
     * fall back to deriving from APP_KEY — the same fail-closed chain as the
     * 'signed' middleware.
     */
    private static function signer(): UrlSigner
    {
        $app = Kernel::app();
        $resolved = $app->bound('url.signer') ? $app->make('url.signer') : null;

        return $resolved instanceof UrlSigner
            ? $resolved
            : UrlSigner::fromAppKey((string) env('APP_KEY', ''));
    }
}
