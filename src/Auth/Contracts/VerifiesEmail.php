<?php

declare(strict_types=1);

namespace Ions\Auth\Contracts;

use DateTimeInterface;

/**
 * Opt-in contract for a host user model whose email must be verified.
 *
 * The framework provides only the verifier ({@see \Ions\Auth\EmailVerification})
 * and the gate ({@see \Ions\Auth\Http\EnsureEmailVerified}). The HOST owns
 * persistence: it stores `email_verified_at` (see the migration stub at
 * `src/Auth/stubs/email_verified_at_column.stub`) and implements these five
 * methods. A user model that does not implement this interface is never gated
 * — verification is strictly opt-in.
 *
 * Security property: the verification link's `hash` is bound to the user's
 * CURRENT {@see getEmailForVerification()} value (sha256), so changing the
 * email before clicking the link invalidates it — the link only ever verifies
 * the address it was issued for.
 */
interface VerifiesEmail
{
    /** The email address the verification link is bound to (hashed into the link). */
    public function getEmailForVerification(): string;

    /**
     * The user key embedded in the signed route as the `id` parameter — the
     * stable identifier the verifier matches against (usually the primary key).
     */
    public function getKeyForVerification(): string|int;

    /** Whether this user's email has already been verified. */
    public function hasVerifiedEmail(): bool;

    /**
     * Persist the verification: the host sets `email_verified_at` to now.
     * Called by {@see \Ions\Auth\EmailVerification::verify()} on success.
     */
    public function markEmailVerified(): void;

    /** When the email was verified, or null while unverified. */
    public function getEmailVerifiedAt(): ?DateTimeInterface;
}
