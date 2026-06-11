<?php

declare(strict_types=1);

namespace Ions\Auth\Notifications;

use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Auth\EmailVerification;
use Ions\Notifications\Notification;
use LogicException;

/**
 * Mails a signed email-verification link to a {@see VerifiesEmail} user.
 *
 * Send it via the notification pipeline:
 *
 *   notify($user, new VerifyEmail());
 *
 * or, with the per-email resend throttle applied, via
 * {@see EmailVerification::sendVerification()}.
 *
 * toMail() builds the link from the notifiable with
 * {@see EmailVerification::verificationUrl()}, so the link is bound to the
 * user's CURRENT email and expires. The route name + lifetime are configurable
 * through the constructor for hosts using a non-default verify route.
 */
final class VerifyEmail extends Notification
{
    public function __construct(
        private readonly string $routeName = 'verification.verify',
        private readonly int $expiresMinutes = 60,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): VerifyEmailMail
    {
        if (!$notifiable instanceof VerifiesEmail) {
            throw new LogicException(sprintf(
                'VerifyEmail can only be sent to a %s; %s does not implement it.',
                VerifiesEmail::class,
                $notifiable::class
            ));
        }

        return new VerifyEmailMail(
            EmailVerification::verificationUrl($notifiable, $this->routeName, $this->expiresMinutes)
        );
    }
}
