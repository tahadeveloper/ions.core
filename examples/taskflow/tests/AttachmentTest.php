<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow attachment download (private, authorized, storage-aware)
|--------------------------------------------------------------------------
| Attachments are stored on the default Storage disk (private for local —
| rooted OUTSIDE public/, not web-accessible) after IonUpload's magic-bytes
| + allow-list validation, and served ONLY through an authorized, route-model
| -bound download action:
|
|   GET /projects/{project}/tasks/{task}/attachments/{attachment}
|
| gated by ['web.auth','verified'] + authorize('view', $task). The action is
| storage-aware: a local disk streams (StreamedResponse, Content-Disposition);
| an s3 disk redirects to a short-lived presigned URL.
|
| These tests use Ions\Filesystem\Storage::fake() — the in-memory disk replaces
| the default disk, so writes never touch the real (now private) root and the
| download streams the stored bytes back.
*/

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Ions\Filesystem\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    cache()->flush();
});

/** Log a verified user into the array web session (what web.auth reads). */
function loginAttach(User $user): void
{
    session()->put('auth_user_id', $user->getKey());
}

/** Minimal valid PNG payload (finfo => image/png) for content-validation. */
function attachPngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

/** Build an in-memory UploadedFile (test mode bypasses is_uploaded_file). */
function attachUpload(string $clientName, string $content): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'af');
    file_put_contents($tmp, $content);

    return new UploadedFile($tmp, $clientName, null, null, true);
}

/**
 * Create a task that owns one attachment, by driving the real upload flow so
 * the file lands on the fake disk under the recorded path. Returns
 * [Project, Task, Attachment].
 *
 * @return array{0: Project, 1: Task, 2: Attachment}
 */
function seedTaskWithAttachment(User $owner, string $name, string $content): array
{
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginAttach($owner);

    $file = attachUpload($name, $content);
    test()->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Has file', 'status' => 'todo'],
        [],
        ['attachment' => $file],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Has file')->where('project_id', $project->getKey())->firstOrFail();
    $attachment = Attachment::query()->where('task_id', $task->getKey())->firstOrFail();

    return [$project, $task, $attachment];
}

/** Capture a StreamedResponse body (getContent() is false for streams). */
function streamBody(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

// --- Security: attachments are stored on the PRIVATE disk, not under public/ -

test('an uploaded attachment is stored on the private disk, never under public/uploads', function () {
    $fake = Storage::fake();

    $owner = User::factory()->create();
    [, , $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    // The DB path is a disk-relative key under attachments/ — NOT a public web
    // path. The file lives on the (private) default disk at exactly that key.
    expect($attachment->path)->toStartWith('attachments/')
        ->and($attachment->path)->not->toContain('public')
        ->and($attachment->path)->not->toContain('uploads/');

    $fake->assertStored($attachment->path);

    // The configured default 'local' disk roots OUTSIDE the public web root —
    // this is the security fix (attachments are not directly fetchable).
    $localRoot = config('filesystem.disks.local.root');
    expect($localRoot)->toBeString()
        ->and($localRoot)->not->toContain('public');
});

// --- A project member can download their attachment (local => stream) -------

test('a project member can download an attachment with the right name + bytes', function () {
    Storage::fake();

    $owner = User::factory()->create();
    [$project, $task, $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    $response = $this->get(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/attachments/' . $attachment->getKey()
    );

    $response->assertOk();

    // StreamedResponse with an attachment Content-Disposition carrying the name.
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    $disposition = (string) $response->headers()->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('diagram.png');

    // The streamed body is exactly the stored bytes.
    expect(streamBody($response->baseResponse))->toBe(attachPngBytes());
});

// --- A non-member is forbidden (403) ----------------------------------------

test('a non-member cannot download an attachment (403)', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    [$project, $task, $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    loginAttach($outsider);

    $this->get(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/attachments/' . $attachment->getKey()
    )->assertStatus(403);
});

// --- Ownership: a cross-task / cross-project attachment 404s ----------------

test('an attachment that belongs to a different task 404s', function () {
    Storage::fake();

    $owner = User::factory()->create();
    [$project, , $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    // A second task in the SAME project that does NOT own the attachment.
    $otherTask = Task::factory()->create(['project_id' => $project->getKey()]);

    loginAttach($owner);

    // The attachment is bound, but it belongs to the first task, not $otherTask.
    $this->get(
        '/projects/' . $project->getKey() . '/tasks/' . $otherTask->getKey() . '/attachments/' . $attachment->getKey()
    )->assertStatus(404);
});

test('an attachment requested under the wrong project 404s', function () {
    Storage::fake();

    $owner = User::factory()->create();
    [, $task, $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    // A different project the owner also owns.
    $otherProject = Project::factory()->create(['owner_id' => $owner->getKey()]);

    loginAttach($owner);

    // The task does not belong to $otherProject => the nesting check 404s before
    // anything is served.
    $this->get(
        '/projects/' . $otherProject->getKey() . '/tasks/' . $task->getKey() . '/attachments/' . $attachment->getKey()
    )->assertStatus(404);
});

// --- Validation is preserved: a disallowed type is rejected, no row, no file -

test('a disallowed attachment type is still rejected before any private write', function () {
    $fake = Storage::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginAttach($owner);

    // SVG is an active-content type, denied by UploadValidator regardless.
    $svg = attachUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Bad upload', 'status' => 'todo'],
        [],
        ['attachment' => $svg],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey()],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Bad upload')->first();
    expect($task)->not->toBeNull()
        ->and(Attachment::query()->where('task_id', $task->getKey())->count())->toBe(0);

    // Content/extension mismatch (a PHP payload claiming .png) is also rejected.
    $mismatch = attachUpload('shell.png', "<?php echo 'pwned'; ?>");
    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Mismatch', 'status' => 'todo'],
        [],
        ['attachment' => $mismatch],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey()],
    )->assertRedirect();

    $task2 = Task::query()->where('title', 'Mismatch')->first();
    expect(Attachment::query()->where('task_id', $task2->getKey())->count())->toBe(0);

    // Nothing escaped onto the private disk.
    $fake->assertMissing('attachments/logo.svg');
});

// --- The task show page links each attachment to the download route ---------

test('the task show page renders a download link for each attachment', function () {
    Storage::fake();

    $owner = User::factory()->create();
    [$project, $task, $attachment] = seedTaskWithAttachment($owner, 'diagram.png', attachPngBytes());

    $show = $this->get('/projects/' . $project->getKey() . '/tasks/' . $task->getKey());
    $show->assertOk();

    $href = '/projects/' . $project->getKey() . '/tasks/' . $task->getKey()
        . '/attachments/' . $attachment->getKey();

    expect($show->content())->toContain($href)
        ->and($show->content())->toContain('diagram.png');
});
