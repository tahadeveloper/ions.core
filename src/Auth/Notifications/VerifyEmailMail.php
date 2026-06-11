<?php

declare(strict_types=1);

namespace Ions\Auth\Notifications;

use Ions\Mail\Mailable;

/**
 * The mail message delivering an email-verification link.
 *
 * Recipient-less by design: the notifications mail channel routes it from the
 * notifiable (the user's ->email / getEmail()). Carries only the verification
 * URL as plain constructor state, so it stays queue-serializable.
 *
 * The default body is a minimal inline HTML/text message. Hosts wanting a
 * branded template should subclass and override build(), or send their own
 * Mailable from a custom VerifyEmail notification.
 */
class VerifyEmailMail extends Mailable
{
    public function __construct(private readonly string $verificationUrl)
    {
    }

    public function build(): void
    {
        $url = $this->verificationUrl;

        $this->subject('Verify your email address')
            ->html(
                '<p>Please verify your email address by clicking the link below:</p>'
                . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Verify Email Address</a></p>'
                . '<p>If you did not create an account, no further action is required.</p>'
            )
            ->text("Please verify your email address by visiting:\n{$url}\n\nIf you did not create an account, no further action is required.");
    }
}
