<?php

declare(strict_types=1);

namespace Ions\Mail;

use Ions\Queue\Job;

/**
 * Queue job wrapping a {@see Mailable}, dispatched by Mailable::queue().
 *
 * The Mailable rides along as plain serialized state (the database driver
 * stores serialize($job) in the payload); build()/render()/send() all happen
 * here in handle(), on the worker. SerializesModels only transforms the
 * job's own top-level properties — the Mailable is not a model, so it is
 * serialized whole, which is exactly why Mailable constructor state should
 * stay plain (see the Mailable class docblock).
 */
final class SendMailableJob extends Job
{
    public function __construct(public Mailable $mailable)
    {
    }

    /**
     * Materialize and send the mailable through the container 'mailer'.
     */
    public function handle(): void
    {
        $this->mailable->send();
    }
}
