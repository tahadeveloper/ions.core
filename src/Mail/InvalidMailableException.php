<?php

declare(strict_types=1);

namespace Ions\Mail;

use LogicException;

/**
 * Thrown when a {@see Mailable} is materialized without the state Symfony
 * Mailer would reject at send time anyway (no recipient, no body, no from
 * address) — surfacing the problem early with an Ions-worded message instead
 * of a transport-level LogicException.
 */
final class InvalidMailableException extends LogicException
{
}
