<?php

declare(strict_types=1);

use Ions\Mail\InvalidMailableException;
use Ions\Mail\Mailable;

beforeEach(function () {
    bootFixtureKernel();

    $this->envBackup = [$_ENV['MAIL_FROM_ADDRESS'] ?? null, $_ENV['MAIL_FROM_NAME'] ?? null];
    $_ENV['MAIL_FROM_ADDRESS'] = 'default@example.test';
    $_ENV['MAIL_FROM_NAME'] = 'Ions Default';
});

afterEach(function () {
    [$address, $name] = $this->envBackup;
    $address === null ? $_ENV = array_diff_key($_ENV, ['MAIL_FROM_ADDRESS' => 0]) : $_ENV['MAIL_FROM_ADDRESS'] = $address;
    $name === null ? $_ENV = array_diff_key($_ENV, ['MAIL_FROM_NAME' => 0]) : $_ENV['MAIL_FROM_NAME'] = $name;
});

/**
 * Build an anonymous Mailable whose build() runs the given closure bound to
 * the instance (so it can call the protected fluent helpers).
 */
function mailableWith(Closure $build): Mailable
{
    return new class ($build) extends Mailable {
        public function __construct(private readonly Closure $buildWith)
        {
        }

        public function build(): void
        {
            ($this->buildWith)->call($this);
        }
    };
}

test('toSymfonyEmail materializes recipients, subject, html and text bodies', function () {
    $email = mailableWith(function (): void {
        $this->to('to@example.test')
            ->cc('cc@example.test')
            ->bcc('bcc@example.test')
            ->replyTo('reply@example.test')
            ->subject('Materialized')
            ->html('<b>html body</b>')
            ->text('text body');
    })->toSymfonyEmail();

    expect($email->getTo()[0]->getAddress())->toBe('to@example.test')
        ->and($email->getCc()[0]->getAddress())->toBe('cc@example.test')
        ->and($email->getBcc()[0]->getAddress())->toBe('bcc@example.test')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('reply@example.test')
        ->and($email->getSubject())->toBe('Materialized')
        ->and($email->getHtmlBody())->toBe('<b>html body</b>')
        ->and($email->getTextBody())->toBe('text body');
});

test('to() accepts a list of addresses and an address => name map', function () {
    $email = mailableWith(function (): void {
        $this->to(['plain@example.test', 'named@example.test' => 'Named Person'])
            ->html('body');
    })->toSymfonyEmail();

    expect($email->getTo())->toHaveCount(2)
        ->and($email->getTo()[0]->getAddress())->toBe('plain@example.test')
        ->and($email->getTo()[0]->getName())->toBe('')
        ->and($email->getTo()[1]->getAddress())->toBe('named@example.test')
        ->and($email->getTo()[1]->getName())->toBe('Named Person');
});

test('the from address falls back to MAIL_FROM_ADDRESS / MAIL_FROM_NAME', function () {
    $email = mailableWith(function (): void {
        $this->to('to@example.test')->html('body');
    })->toSymfonyEmail();

    expect($email->getFrom()[0]->getAddress())->toBe('default@example.test')
        ->and($email->getFrom()[0]->getName())->toBe('Ions Default');
});

test('an explicit from() overrides the env default', function () {
    $email = mailableWith(function (): void {
        $this->to('to@example.test')->from('explicit@example.test', 'Explicit')->html('body');
    })->toSymfonyEmail();

    expect($email->getFrom()[0]->getAddress())->toBe('explicit@example.test')
        ->and($email->getFrom()[0]->getName())->toBe('Explicit');
});

test('the subject is optional (Symfony allows a subject-less email)', function () {
    $email = mailableWith(function (): void {
        $this->to('to@example.test')->html('body');
    })->toSymfonyEmail();

    expect($email->getSubject())->toBeNull();
});

test('attach() adds a file attachment with explicit name and mime type', function () {
    $path = tempnam(sys_get_temp_dir(), 'ions_mailable_');
    file_put_contents($path, 'attachment-bytes');

    try {
        $email = mailableWith(function () use ($path): void {
            $this->to('to@example.test')
                ->html('body')
                ->attach($path, 'report.txt', 'text/plain');
        })->toSymfonyEmail();

        $attachments = $email->getAttachments();
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe('report.txt')
            ->and($attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype())->toBe('text/plain')
            ->and($attachments[0]->getBody())->toBe('attachment-bytes');
    } finally {
        @unlink($path);
    }
});

test('the materialized email is stamped with the Mailable FQCN header', function () {
    $mailable = mailableWith(function (): void {
        $this->to('to@example.test')->html('body');
    });

    $email = $mailable->toSymfonyEmail();

    expect($email->getHeaders()->getHeaderBody(Mailable::CLASS_HEADER))->toBe($mailable::class);
});

test('view() renders the Twig template with its data at materialize time', function () {
    // The fixture app's app.twig.source is sys_get_temp_dir() (see
    // tests/fixtures/app/config/app.php), so a template dropped there renders
    // through the real shared 'view' factory path.
    $template = 'ions_mailable_' . uniqid('', true) . '.twig';
    file_put_contents(sys_get_temp_dir() . '/' . $template, 'Hello {{ name }}!');

    try {
        $email = mailableWith(function () use ($template): void {
            $this->to('to@example.test')->view($template, ['name' => 'Ion']);
        })->toSymfonyEmail();

        expect($email->getHtmlBody())->toBe('Hello Ion!');
    } finally {
        @unlink(sys_get_temp_dir() . '/' . $template);
    }
});

test('view() takes precedence over html() regardless of call order', function () {
    $template = 'ions_mailable_' . uniqid('', true) . '.twig';
    file_put_contents(sys_get_temp_dir() . '/' . $template, 'view wins');

    try {
        $viewLast = mailableWith(function () use ($template): void {
            $this->to('to@example.test')->html('<b>literal html</b>')->view($template);
        })->toSymfonyEmail();

        $viewFirst = mailableWith(function () use ($template): void {
            $this->to('to@example.test')->view($template)->html('<b>literal html</b>');
        })->toSymfonyEmail();

        expect($viewLast->getHtmlBody())->toBe('view wins')
            ->and($viewFirst->getHtmlBody())->toBe('view wins');
    } finally {
        @unlink(sys_get_temp_dir() . '/' . $template);
    }
});

test('calling toSymfonyEmail twice does not duplicate state', function () {
    $mailable = mailableWith(function (): void {
        $this->to('to@example.test')->subject('Once')->html('body');
    });

    $mailable->toSymfonyEmail();
    $email = $mailable->toSymfonyEmail();

    expect($email->getTo())->toHaveCount(1)
        ->and($email->getSubject())->toBe('Once');
});

test('a mailable with no recipients throws a clear exception', function () {
    expect(fn () => mailableWith(function (): void {
        $this->html('body');
    })->toSymfonyEmail())->toThrow(InvalidMailableException::class, 'recipient');
});

test('a mailable with no body throws a clear exception', function () {
    expect(fn () => mailableWith(function (): void {
        $this->to('to@example.test')->subject('Empty');
    })->toSymfonyEmail())->toThrow(InvalidMailableException::class, 'body');
});

test('a missing from address (no env fallback) throws a clear exception', function () {
    $_ENV['MAIL_FROM_ADDRESS'] = '';

    expect(fn () => mailableWith(function (): void {
        $this->to('to@example.test')->html('body');
    })->toSymfonyEmail())->toThrow(InvalidMailableException::class, 'MAIL_FROM_ADDRESS');
});
