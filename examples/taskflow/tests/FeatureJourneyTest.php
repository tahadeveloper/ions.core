<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow full-journey coverage (13.7)
|--------------------------------------------------------------------------
| ONE cohesive end-to-end narrative that threads EVERY subsystem in a single
| user story — distinct from the per-feature suites (Auth/Crud/Async/Sharing/
| Database), which test each feature in isolation. Each step asserts the right
| subsystem with the right fake:
|
|   register (web form + WelcomeMail)              → Mail::fake
|   email-verify via the signed link               → EmailVerification + signed mw
|   enable 2FA (TOTP) + login through the challenge → TwoFactor + Encrypter at rest
|   create a project (encrypted note at rest)       → Encrypter + FormRequest
|   add a member (membership relation)              → Project::members()
|   create a task with an attachment                → IonUpload magic-bytes + Storage::fake
|   assign the task                                 → dispatch + Notifications::fake
|   post a comment                                  → CommentAdded notify
|   mark the task done                              → Http::fake completion webhook
|   mint a signed share link                        → signedRoute + 'signed'
|   fetch the public board twice                    → response cache MISS → HIT
|   run the scheduled prune                          → App\Schedule (scheduler)
|
| End state: the data is consistent — the project has the task, the task is
| done, the attachment row exists, the notifications were sent, the note
| round-trips through the Encrypter.
|
| The journey reuses the EXACT helpers/patterns of the per-feature tests:
| beforeEach(migrate + csrf-off + cache-flush), session()->put('auth_user_id'),
| the 2FA enrol/challenge flows, Storage::fake + tinyPngBytes()/makeUpload()
| (global helpers from CrudTest), Notifications::fake, Http::fake, and the
| SharingTest APP_DEBUG=false + cache.response.enabled toggle (try/finally
| restore). The scheduler is driven exactly as AsyncTest drives it.
*/

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Notifications\TaskAssigned;
use App\Schedule;
use Ions\Auth\EmailVerification;
use Ions\Auth\TwoFactor;
use Ions\Bundles\Path;
use Ions\Filesystem\Storage;
use Ions\Security\Encrypter;
use Ions\Support\Http;
use Ions\Support\Mail;
use Ions\Support\Notifications;
use Ions\Support\Schedule as ScheduleFacade;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    cache()->flush();
});

/** Path + query of an absolute app URL (signed/verification links). */
function journeyUri(string $absoluteUrl): string
{
    $parts = parse_url($absoluteUrl);

    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/**
 * Minimal valid PNG payload (finfo => image/png) for magic-byte validation.
 * Uniquely named so this file stays decoupled from CrudTest's globals (Pest
 * loads every test file, so a shared name would redeclare-error).
 */
function journeyPngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

/** Build an in-memory UploadedFile (test mode bypasses is_uploaded_file). */
function journeyUpload(string $clientName, string $content): \Symfony\Component\HttpFoundation\File\UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'fj');
    file_put_contents($tmp, $content);

    return new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, $clientName, null, null, true);
}

test('the full Taskflow journey threads every subsystem with consistent end state', function () {
    // Fakes for the whole story. Notifications + Mail + Storage + Http are all
    // intercepted so no real channel is touched; assertions below prove each
    // subsystem fired through its public API.
    $notifications = Notifications::fake();
    $mail = Mail::fake();
    $disk = Storage::fake();
    Http::fake(['*' => 'ok']);

    // -- 1. REGISTER (web form flow + WelcomeMail + signed verify link) -------
    // Subsystem: RegisterController + EmailVerification + Mailable + FormRequest.
    $this->post('/register', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect('http://localhost:8000/login');

    $user = User::query()->where('email', 'grace@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    // Registration mailed the welcome message.
    $mail->assertSent(\App\Mail\WelcomeMail::class);

    // -- 2. EMAIL VERIFY (signed, expiring link) ------------------------------
    // Subsystem: Ions\Auth\EmailVerification + the 'signed' middleware.
    $verifyUrl = EmailVerification::verificationUrl($user);
    $this->get(journeyUri($verifyUrl))->assertRedirect('http://localhost:8000/login');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // -- 3. ENABLE 2FA (TOTP secret encrypted at rest) ------------------------
    // Subsystem: Ions\Auth\TwoFactor + Ions\Security\Encrypter.
    session()->put('auth_user_id', $user->getKey());
    $this->get('/2fa')->assertOk();
    $setupSecret = (string) session('2fa.setup_secret');
    expect($setupSecret)->not->toBe('');

    $this->post('/2fa', ['code' => TwoFactor::code($setupSecret)])
        ->assertRedirect('http://localhost:8000/dashboard');

    $user = $user->fresh();
    // The secret is stored ENCRYPTED (iev1: envelope), not in the clear.
    expect($user->two_factor_secret)->toStartWith('iev1:');
    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    expect($encrypter->decrypt((string) $user->two_factor_secret))->toBe($setupSecret);

    // -- 4. LOGIN THROUGH THE 2FA CHALLENGE -----------------------------------
    // Subsystem: LoginController + TwoFactor challenge (session-gated handoff).
    // Drop the enrolment session so we log in clean.
    session()->forget('auth_user_id');

    $this->post('/login', ['email' => $user->email, 'password' => 'a-strong-password'])
        ->assertRedirect('http://localhost:8000/login/2fa');
    expect(session('auth_user_id'))->toBeNull()
        ->and(session('2fa.pending_id'))->toBe($user->getKey());

    $this->post('/login/2fa', ['code' => TwoFactor::code($setupSecret)])
        ->assertRedirect('http://localhost:8000/dashboard');
    expect(session('auth_user_id'))->toBe($user->getKey());

    // -- 5. CREATE A PROJECT (encrypted note at rest + FormRequest) -----------
    // Subsystem: ProjectController + StoreProjectRequest + Encrypter.
    $this->post('/projects', ['name' => 'Mark I', 'note' => 'compiler milestone'])
        ->assertRedirect();

    $project = Project::query()->where('name', 'Mark I')->first();
    expect($project)->not->toBeNull()
        ->and((int) $project->owner_id)->toBe($user->getKey());

    // The note column holds ciphertext at rest; the accessor round-trips it.
    expect((string) $project->note)->toStartWith('iev1:')
        ->not->toContain('compiler milestone');
    expect($project->noteText())->toBe('compiler milestone');

    // -- 6. ADD A MEMBER (membership relation) --------------------------------
    // Subsystem: Project::members() pivot — the relation the policies read.
    $member = User::factory()->create(['email' => 'member@example.com']);
    $project->members()->sync([$user->getKey(), $member->getKey()]);
    expect($project->members()->where('users.id', $member->getKey())->exists())->toBeTrue();

    // -- 7. CREATE A TASK WITH AN ATTACHMENT ----------------------------------
    // Subsystem: TaskController + IonUpload magic-bytes + Storage::fake.
    $file = journeyUpload('diagram.png', journeyPngBytes());
    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Wire the relays', 'status' => 'todo'],
        [],
        ['attachment' => $file],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Wire the relays')->first();
    expect($task)->not->toBeNull()
        ->and((int) $task->project_id)->toBe($project->getKey());

    $attachment = Attachment::query()->where('task_id', $task->getKey())->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->original_name)->toBe('diagram.png')
        ->and($attachment->path)->toStartWith('attachments/');
    // The validated write landed on the FAKE disk, never the real uploads dir.
    $disk->assertStored(Path::files('attachments') . '/' . basename((string) $attachment->path));

    // -- 8. ASSIGN THE TASK (dispatch the notification job) -------------------
    // Subsystem: TaskController::assign → dispatch(SendTaskAssignedNotification).
    $this->post(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/assign',
        ['assignee' => $member->getKey()]
    )->assertRedirect();
    expect((int) $task->fresh()->assignee_id)->toBe($member->getKey());

    // Run the dispatched job in-process; it notifies the assignee.
    (new \App\Jobs\SendTaskAssignedNotification($task->getKey(), $member->getKey()))->handle();
    $notifications->assertSentTo($member, TaskAssigned::class);

    // -- 9. POST A COMMENT (CommentAdded notify) ------------------------------
    // Subsystem: TaskController::comment → notify the assignee (CommentAdded).
    // The owner comments → the member (assignee) is notified.
    $this->post(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/comment',
        ['body' => 'Relays look good — ship it.']
    )->assertRedirect();
    expect($task->comments()->count())->toBe(1);
    $notifications->assertSentTo($member, CommentAdded::class);

    // -- 10. MARK THE TASK DONE (Http::fake completion webhook) ---------------
    // Subsystem: TaskController::update + Ions\Support\Http completion webhook.
    $this->post('/projects/' . $project->getKey() . '/tasks/' . $task->getKey(), [
        'title' => 'Wire the relays',
        'status' => 'done',
    ])->assertRedirect();
    expect($task->fresh()->status)->toBe('done');
    Http::assertSent('https://hooks.taskflow.test/task-completed');
    Http::assertSentCount(1);

    // -- 11. MINT A SIGNED SHARE LINK -----------------------------------------
    // Subsystem: ShareController + signedRoute() + UrlSigner.
    $this->post('/projects/' . $project->getKey() . '/share')
        ->assertRedirect('http://localhost:8000/projects/' . $project->getKey());
    $shareUrl = flash('shareUrl');
    expect($shareUrl)->toBeString()
        ->and($shareUrl)->toContain('/share/board/' . $project->getKey())
        ->and($shareUrl)->toContain('signature=');

    // -- 12. FETCH THE PUBLIC BOARD TWICE (response cache MISS → HIT) ---------
    // Subsystem: 'signed' + 'cache.response'. Mirrors SharingTest exactly: the
    // cache middleware bypasses on APP_DEBUG, so force APP_DEBUG=false for the
    // duration and restore it in finally. A fresh signed link keeps the request
    // anonymous (no session), so the board is cacheable.
    $boardUri = journeyUri(signedRoute('share.board', ['project' => $project->getKey()], time() + 3600));
    // Fully clear the array session: the earlier steps (2FA, login, the share
    // redirect's flashed shareUrl) wrote data that survives in-process, and
    // ResponseCache treats ANY non-empty session OR flash bag as stateful
    // (per-user, never stored). Clear both the attribute bag and the flash bag
    // so the board request is anonymous → cacheable.
    session()->flush();
    session()->getSession()->getFlashBag()->clear();

    $original = $_ENV['APP_DEBUG'] ?? null;
    $_ENV['APP_DEBUG'] = 'false';
    config(['cache.response.enabled' => true]);

    try {
        $first = $this->get($boardUri);
        $first->assertOk();
        expect($first->headers()->get('X-Ions-Cache'))->toBe('MISS');
        expect($first->content())->toContain('Mark I');

        $second = $this->get($boardUri);
        $second->assertOk();
        expect($second->headers()->get('X-Ions-Cache'))->toBe('HIT');
    } finally {
        if ($original === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $original;
        }
    }

    // -- 13. RUN THE SCHEDULED PRUNE (the scheduler) --------------------------
    // Subsystem: App\Schedule + Ions\Support\Schedule scheduler. The daily
    // prune-read-notifications task runs at a frozen midnight UTC (same as
    // AsyncTest). The registered tasks include the prune + weekly digest.
    $scheduler = ScheduleFacade::scheduler();
    $names = array_map(static fn ($t) => $t->getName(), $scheduler->tasks());
    expect($names)->toContain('prune-read-notifications');

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 00:00:00'), static fn () => 0);
    expect($summary['failed'])->toBe(0)
        ->and($summary['ran'])->toBeGreaterThanOrEqual(1);

    // The prune helper itself removes long-read notifications.
    Schedule::pruneReadNotifications();

    // -- END STATE: the data is consistent ------------------------------------
    $project = $project->fresh();
    $task = $task->fresh();

    expect($project->tasks()->count())->toBe(1)                  // project has the task
        ->and($project->tasks()->first()->getKey())->toBe($task->getKey())
        ->and($task->status)->toBe('done')                       // task is done
        ->and((int) $task->assignee_id)->toBe($member->getKey()) // assigned to the member
        ->and(Attachment::query()->where('task_id', $task->getKey())->count())->toBe(1) // attachment row
        ->and($project->noteText())->toBe('compiler milestone');  // encrypted note round-trips
});
