<?php

declare(strict_types=1);

namespace IonsFixture;

/**
 * Subclass fixture for the inheritance-aware MailFake matching:
 * Mail::assertSent(WelcomeMailable::class) must match a sent
 * VipWelcomeMailable (is_a on the X-Ions-Mailable header FQCN).
 */
final class VipWelcomeMailable extends WelcomeMailable
{
    public function build(): void
    {
        parent::build();

        $this->subject('Welcome aboard, VIP');
    }
}
