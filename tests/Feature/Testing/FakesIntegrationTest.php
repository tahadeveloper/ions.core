<?php

declare(strict_types=1);

use Ions\Events\RequestHandled;
use Ions\Filesystem\Storage;
use Ions\Foundation\Kernel;
use Ions\Support\Event;
use Ions\Support\Mail;
use Ions\Support\Queue;
use Ions\Testing\Fakes\EventFake;
use Ions\Testing\Fakes\QueueFake;
use Ions\Testing\TestCase;
use IonsFixture\RecordingJob;
use IonsFixture\RecordingListener;

/*
|--------------------------------------------------------------------------
| Fakes — integration with Ions\Testing\TestCase
|--------------------------------------------------------------------------
| Class-based so the real per-test boot/reset lifecycle is exercised. The
| isolation tests below rely on PHPUnit's in-class declaration order:
| test_..._installs_every_fake runs before test_..._previous_test.
*/

class FakesIntegrationTest extends TestCase
{
    protected string $basePath = __DIR__ . '/../../fixtures/app';

    /** Path used to prove fake storage writes never reach the real disk across tests. */
    private static string $isolationPath = '';

    public function test_event_fake_intercepts_the_frameworks_request_handled_event(): void
    {
        RecordingListener::$handled = [];

        $events = Event::fake();

        $this->get('/ping')->assertOk();

        // the fake recorded the kernel's own lifecycle event...
        $events->assertFired(RequestHandled::class, function (RequestHandled $event): bool {
            return $event->response->getStatusCode() === 200;
        });

        // ...and the configured listener (events.listen fixture map) never ran
        $this->assertSame([], RecordingListener::$handled);
    }

    public function test_request_handled_reaches_real_listeners_when_no_fake_is_installed(): void
    {
        RecordingListener::$handled = [];

        $this->get('/ping')->assertOk();

        $this->assertNotSame([], RecordingListener::$handled);
    }

    public function test_isolation_setup_installs_every_fake(): void
    {
        self::$isolationPath = 'isolation-' . uniqid('', true) . '.txt';

        Queue::fake();
        Event::fake();
        Mail::fake();
        $disk = Storage::fake();

        dispatch(new RecordingJob('leaky?'));
        Storage::put(self::$isolationPath, 'memory-only');

        Queue::assertDispatched(RecordingJob::class);
        $disk->assertStored(self::$isolationPath);
    }

    public function test_isolation_fakes_installed_in_the_previous_test_are_gone(): void
    {
        $app = Kernel::app();

        $this->assertNotInstanceOf(QueueFake::class, $app->get('queue'));
        $this->assertNotInstanceOf(EventFake::class, $app->get('events'));

        // the mailer binding is back to the lazy provider closure — the fake
        // instance from the previous test would otherwise count as resolved
        $this->assertFalse($app->resolved('mailer'));

        // the default disk is the real (local) one again, and the file written
        // into the previous test's in-memory disk does not exist anywhere
        $this->assertNotSame('', self::$isolationPath);
        $this->assertFalse(Storage::exists(self::$isolationPath));
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/' . self::$isolationPath);
    }
}
