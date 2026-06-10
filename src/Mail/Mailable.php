<?php

declare(strict_types=1);

namespace Ions\Mail;

use Ions\Foundation\Kernel;
use Ions\View\ViewFactory;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Base class for host-app mail messages.
 *
 * Subclasses take their data through the constructor and implement build(),
 * which configures the message with the protected fluent helpers:
 *
 *   final class WelcomeMail extends Mailable {
 *       public function __construct(private string $email, private string $name) {}
 *       public function build(): void {
 *           $this->to($this->email)
 *               ->subject('Welcome!')
 *               ->view('emails/welcome.twig', ['name' => $this->name]);
 *       }
 *   }
 *
 *   (new WelcomeMail($email, $name))->send();              // now, via 'mailer'
 *   (new WelcomeMail($email, $name))->queue('database');   // later, via 'queue'
 *
 * Lifecycle / serialization design: a Mailable is a plain data object until
 * send time. Constructing one touches NOTHING in the container; build() runs
 * — and view() templates render — only inside toSymfonyEmail(), i.e. when
 * send() executes (for queue() that is in the worker's handle(), never at
 * dispatch time). queue() therefore serializes only the subclass's own
 * constructor state, so keep that state plain (ids/scalars/arrays, no open
 * resources; nested Eloquent models are serialized whole, prefer ids).
 *
 * Faking: send() hands the materialized Symfony Email to the container
 * 'mailer', so Mail::fake() intercepts it. Every materialized email is
 * stamped with an 'X-Ions-Mailable: FQCN' header ({@see self::CLASS_HEADER})
 * which is what lets Mail::assertSent(WelcomeMail::class) match by Mailable
 * class without any fake-side state.
 *
 * If view() and html() are both called, the rendered view wins.
 */
abstract class Mailable
{
    /**
     * Header stamped on every materialized email carrying the Mailable FQCN.
     * {@see \Ions\Testing\Fakes\MailFake::assertSent()} matches Mailable
     * class-strings against it.
     */
    public const CLASS_HEADER = 'X-Ions-Mailable';

    /** @var list<array{string, string}> address/name pairs */
    private array $toAddresses = [];

    /** @var list<array{string, string}> */
    private array $ccAddresses = [];

    /** @var list<array{string, string}> */
    private array $bccAddresses = [];

    /** @var list<array{string, string}> */
    private array $replyToAddresses = [];

    /** @var array{string, string}|null */
    private ?array $fromAddress = null;

    private ?string $mailSubject = null;

    private ?string $htmlBody = null;

    private ?string $textBody = null;

    private ?string $viewTemplate = null;

    /** @var array<string, mixed> */
    private array $viewData = [];

    /** @var list<array{path: string, name: string|null, mime: string|null}> */
    private array $attachments = [];

    /**
     * Configure the message using the protected fluent helpers. Runs at send
     * time (in the worker, for queued mailables) — never at construct or
     * dispatch time.
     */
    abstract public function build(): void;

    /**
     * Build + render + hand the Symfony Email to the container 'mailer'
     * (so Mail::fake() intercepts it).
     */
    public function send(): void
    {
        \Ions\Support\Mail::send($this->toSymfonyEmail());
    }

    /**
     * Queue the mailable as a {@see SendMailableJob} via the dispatch()
     * helper. Nothing is built or rendered here — the job carries this
     * (plain-state) Mailable and handle() calls send() on the worker.
     *
     * @return mixed The driver's push result (e.g. the database job id; the
     *               sync driver runs the job immediately and returns 0).
     */
    public function queue(?string $connection = null, ?string $queue = null): mixed
    {
        $job = new SendMailableJob($this);

        if ($connection !== null) {
            $job->onConnection($connection);
        }
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        return dispatch($job);
    }

    /**
     * Build and materialize the message as a Symfony Email — useful directly
     * in tests and for notification channels.
     *
     * Validation mirrors what Symfony Mailer would reject at send time, but
     * with clear messages: at least one recipient (to/cc/bcc), a from address
     * (explicit or MAIL_FROM_ADDRESS), and a body (view/html/text or at least
     * one attachment). The subject is optional. Repeated calls rebuild from a
     * clean slate, so state never accumulates across materializations.
     *
     * @throws InvalidMailableException
     */
    public function toSymfonyEmail(): Email
    {
        $this->resetState();
        $this->build();

        if ($this->toAddresses === [] && $this->ccAddresses === [] && $this->bccAddresses === []) {
            throw new InvalidMailableException(sprintf(
                'Mailable [%s] has no recipient: call to(), cc() or bcc() in build().',
                static::class
            ));
        }

        $from = $this->fromAddress ?? $this->defaultFrom();
        if ($from === null) {
            throw new InvalidMailableException(sprintf(
                'Mailable [%s] has no from address: call from() in build() or set MAIL_FROM_ADDRESS.',
                static::class
            ));
        }

        $html = $this->viewTemplate !== null ? $this->renderView($this->viewTemplate, $this->viewData) : $this->htmlBody;
        if ($html === null && $this->textBody === null && $this->attachments === []) {
            throw new InvalidMailableException(sprintf(
                'Mailable [%s] has no body: call view(), html() or text() (or attach a file) in build().',
                static::class
            ));
        }

        $email = (new Email())->from(new Address($from[0], $from[1]));

        foreach ($this->toAddresses as [$address, $name]) {
            $email->addTo(new Address($address, $name));
        }
        foreach ($this->ccAddresses as [$address, $name]) {
            $email->addCc(new Address($address, $name));
        }
        foreach ($this->bccAddresses as [$address, $name]) {
            $email->addBcc(new Address($address, $name));
        }
        foreach ($this->replyToAddresses as [$address, $name]) {
            $email->addReplyTo(new Address($address, $name));
        }

        if ($this->mailSubject !== null) {
            $email->subject($this->mailSubject);
        }
        if ($html !== null) {
            $email->html($html);
        }
        if ($this->textBody !== null) {
            $email->text($this->textBody);
        }

        foreach ($this->attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['name'], $attachment['mime']);
        }

        $email->getHeaders()->addTextHeader(self::CLASS_HEADER, static::class);

        return $email;
    }

    // ------------------------------------------------------------------
    // Fluent build() helpers
    // ------------------------------------------------------------------

    /**
     * Add To recipients. Accepts a single address, a list of addresses, or an
     * address => display-name map (mixed entries are fine).
     *
     * @param string|array<int|string, string> $addresses
     */
    protected function to(string|array $addresses): static
    {
        $this->toAddresses = [...$this->toAddresses, ...$this->normalizeAddresses($addresses)];

        return $this;
    }

    /**
     * Add Cc recipients ({@see to()} for the accepted shapes).
     *
     * @param string|array<int|string, string> $addresses
     */
    protected function cc(string|array $addresses): static
    {
        $this->ccAddresses = [...$this->ccAddresses, ...$this->normalizeAddresses($addresses)];

        return $this;
    }

    /**
     * Add Bcc recipients ({@see to()} for the accepted shapes).
     *
     * @param string|array<int|string, string> $addresses
     */
    protected function bcc(string|array $addresses): static
    {
        $this->bccAddresses = [...$this->bccAddresses, ...$this->normalizeAddresses($addresses)];

        return $this;
    }

    /**
     * Add Reply-To addresses ({@see to()} for the accepted shapes).
     *
     * @param string|array<int|string, string> $addresses
     */
    protected function replyTo(string|array $addresses): static
    {
        $this->replyToAddresses = [...$this->replyToAddresses, ...$this->normalizeAddresses($addresses)];

        return $this;
    }

    /**
     * Set the sender. When never called, MAIL_FROM_ADDRESS / MAIL_FROM_NAME
     * are used (same env fallback as the newMailerDsn() helper).
     */
    protected function from(string $address, ?string $name = null): static
    {
        $this->fromAddress = [$address, $name ?? ''];

        return $this;
    }

    protected function subject(string $subject): static
    {
        $this->mailSubject = $subject;

        return $this;
    }

    /**
     * Set a literal HTML body. A view() template, when set, takes precedence.
     */
    protected function html(string $html): static
    {
        $this->htmlBody = $html;

        return $this;
    }

    /**
     * Set the plain-text body (can accompany an html/view body).
     */
    protected function text(string $text): static
    {
        $this->textBody = $text;

        return $this;
    }

    /**
     * Use a Twig template as the HTML body. The template name and data are
     * stored as plain state; rendering happens at SEND time through the
     * shared 'view' factory (app.twig.* config — the same environment the
     * render() helper displays through), so queued mailables render in the
     * worker with the data captured here.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = []): static
    {
        $this->viewTemplate = $template;
        $this->viewData = $data;

        return $this;
    }

    /**
     * Attach a file from disk. Name and mime default to what Symfony infers
     * from the path.
     */
    protected function attach(string $path, ?string $name = null, ?string $mime = null): static
    {
        $this->attachments[] = ['path' => $path, 'name' => $name, 'mime' => $mime];

        return $this;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param string|array<int|string, string> $addresses
     *
     * @return list<array{string, string}>
     */
    private function normalizeAddresses(string|array $addresses): array
    {
        if (is_string($addresses)) {
            return [[$addresses, '']];
        }

        $normalized = [];
        foreach ($addresses as $key => $value) {
            // int key => the value is the address; string key => address => name
            $normalized[] = is_string($key) ? [$key, $value] : [$value, ''];
        }

        return $normalized;
    }

    /**
     * @return array{string, string}|null
     */
    private function defaultFrom(): ?array
    {
        $address = (string) env('MAIL_FROM_ADDRESS', '');

        return $address === '' ? null : [$address, (string) env('MAIL_FROM_NAME', '')];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderView(string $template, array $data): string
    {
        $factory = Kernel::app()->get('view');
        if (!$factory instanceof ViewFactory) {
            throw new RuntimeException('The container "view" binding is not a ViewFactory; cannot render the mailable view.');
        }

        return $factory->make()->render($template, $data);
    }

    /**
     * Clear all build() state so repeated materializations start clean.
     */
    private function resetState(): void
    {
        $this->toAddresses = [];
        $this->ccAddresses = [];
        $this->bccAddresses = [];
        $this->replyToAddresses = [];
        $this->fromAddress = null;
        $this->mailSubject = null;
        $this->htmlBody = null;
        $this->textBody = null;
        $this->viewTemplate = null;
        $this->viewData = [];
        $this->attachments = [];
    }
}
