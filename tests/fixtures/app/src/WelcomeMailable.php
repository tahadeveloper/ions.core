<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Mail\Mailable;

/**
 * Named (serializable) Mailable fixture. Used by the Mailable feature tests
 * for FQCN assertSent() matching and the queue serialize/unserialize
 * round-trip — anonymous classes cannot be unserialized, so this lives here.
 *
 * Constructor state is intentionally plain scalars: that is exactly what a
 * queued Mailable should carry (build() runs at SEND time, in the worker).
 */
final class WelcomeMailable extends Mailable
{
    public function __construct(
        private string $recipient,
        private string $name,
    ) {
    }

    public function build(): void
    {
        $this->to($this->recipient)
            ->from('noreply@example.test', 'Ions Fixture')
            ->subject('Welcome aboard')
            ->html('<p>Welcome, ' . $this->name . '</p>');
    }
}
