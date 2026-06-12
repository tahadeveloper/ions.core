<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Jobs\SendTaskAssignedNotification;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Services\AvatarFetcher;
use DateTimeImmutable;
use Ions\Filesystem\Storage;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Security\UploadValidator;
use Ions\Support\Request;
use Ions\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Web CRUD for tasks, nested under a project. Both placeholders in
 * /projects/{project}/tasks/{task} are route-model-bound (10.2); show/edit/
 * update/destroy additionally assert the bound task actually belongs to the
 * bound project (binding fetches by global key, so the nesting is verified
 * here, 404 otherwise).
 *
 * Authorization delegates to TaskPolicy (which defers to the parent project's
 * ProjectPolicy): view/update for owner-or-member, delete owner-only.
 *
 * store()/update() accept an optional `attachment` file: its extension
 * allow-list AND magic bytes are validated (the same UploadValidator IonUpload
 * uses — active types such as .svg/.php and content/extension mismatches are
 * refused, oversize files rejected) BEFORE it is written, via the Storage
 * abstraction, onto the PRIVATE default disk (var/storage for local; the bucket
 * for s3) — never under the public web root. An Attachment row records the
 * disk-relative key. On rejection the user is redirected back with an error and
 * nothing is written.
 *
 * Attachments are downloadable only through show()'s authorized,
 * member-gated downloadAttachment() action — storage-aware: a local disk
 * streams the bytes (Content-Disposition: attachment), an s3 disk redirects to
 * a short-lived (5-minute) presigned URL.
 */
final class TaskController extends BaseController
{
    protected string $viewPath = 'tasks';

    /** Disk-relative sub-directory (key prefix) for task attachments. */
    private const ATTACHMENT_DIR = 'attachments';

    /** Hard cap on an accepted attachment (bytes). */
    private const MAX_ATTACHMENT_BYTES = 2_097_152; // 2 MiB

    /**
     * Allow-list for attachment extensions (mirrors IonUpload's default).
     * UploadValidator additionally denies active-content types (.php/.svg/…)
     * and enforces magic-bytes agreement, regardless of this list.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
    ];

    public function show(Request $request, Project $project, Task $task): View
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('view', $task);

        return $this->view('show', [
            'project' => $project,
            'task' => $task,
            'attachments' => $task->attachments()->orderByDesc('id')->get(),
            'comments' => $task->comments()->orderBy('id')->get(),
            'status' => flash('status'),
            'error' => flash('error'),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        $this->authorize('update', $project);

        return $this->view('create', ['project' => $project]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = StoreTaskRequest::validate($request);

        $task = Task::query()->create([
            'project_id' => $project->getKey(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Task::STATUS_TODO,
        ]);

        $error = $this->storeAttachment($request, $task);
        if ($error !== null) {
            return back('/projects/' . $project->getKey())->with('error', $error);
        }

        return redirect('/projects/' . $project->getKey() . '/tasks/' . $task->getKey())
            ->with('status', 'Task created.');
    }

    public function edit(Request $request, Project $project, Task $task): View
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('update', $task);

        return $this->view('edit', ['project' => $project, 'task' => $task]);
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('update', $task);
        $data = UpdateTaskRequest::validate($request);

        $wasDone = $task->status === Task::STATUS_DONE;
        $newStatus = $data['status'] ?? $task->status;

        $task->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $newStatus,
        ]);

        // Fire the completion webhook on the todo/doing -> done transition only
        // (8.5 Http client). Http::fake() intercepts this in tests.
        if (!$wasDone && $newStatus === Task::STATUS_DONE) {
            (new AvatarFetcher())->notifyTaskDone($task);
        }

        $error = $this->storeAttachment($request, $task);
        if ($error !== null) {
            return back('/projects/' . $project->getKey() . '/tasks/' . $task->getKey())
                ->with('error', $error);
        }

        return redirect('/projects/' . $project->getKey() . '/tasks/' . $task->getKey())
            ->with('status', 'Task updated.');
    }

    public function destroy(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('delete', $task);
        $task->delete();

        return redirect('/projects/' . $project->getKey())->with('status', 'Task deleted.');
    }

    /**
     * Assign the task to a project member. On success the task records the new
     * assignee and a SendTaskAssignedNotification job is dispatched — its
     * handle() notifies the assignee (mail + database channels). The `assignee`
     * input must be a member of (or own) the project, else a redirect-back error.
     */
    public function assign(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('update', $task);

        $assigneeId = (int) $request->request->get('assignee');
        $assignee = User::query()->find($assigneeId);

        $isMember = $assignee !== null && (
            (int) $project->owner_id === $assignee->getKey()
            || $project->members()->whereKey($assignee->getKey())->exists()
        );

        if (!$isMember) {
            return back('/projects/' . $project->getKey() . '/tasks/' . $task->getKey())
                ->with('error', 'Assignee must be a project member.');
        }

        $task->update(['assignee_id' => $assignee->getKey()]);

        dispatch(new SendTaskAssignedNotification($task->getKey(), $assignee->getKey()));

        return redirect('/projects/' . $project->getKey() . '/tasks/' . $task->getKey())
            ->with('status', 'Task assigned.');
    }

    /**
     * Post a comment on the task (member-only). Notifies the task's assignee
     * — when it isn't the commenter — on the mail + database channels via the
     * CommentAdded notification (the in-app list reads the database row).
     */
    public function comment(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('update', $task);

        $body = trim((string) $request->request->get('body', ''));
        $url = '/projects/' . $project->getKey() . '/tasks/' . $task->getKey();
        if ($body === '') {
            return back($url)->with('error', 'Comment cannot be empty.');
        }

        /** @var User $author */
        $author = $request->attributes->get('auth_user');
        $task->comments()->create(['author_id' => $author->getKey(), 'body' => $body]);

        // Notify the assignee (unless they wrote the comment themselves).
        $assigneeId = $task->assignee_id;
        if ($assigneeId !== null && (int) $assigneeId !== $author->getKey()) {
            $assignee = User::query()->find($assigneeId);
            if ($assignee !== null) {
                notify($assignee, new CommentAdded($task->getKey(), (string) $task->title, (string) $author->name));
            }
        }

        return redirect($url)->with('status', 'Comment posted.');
    }

    /**
     * Stream/redirect an attachment download (member-only).
     *
     * The attachment is route-model-bound; we assert it belongs to the bound
     * task AND the task to the bound project (404 otherwise), then authorize
     * 'view' on the task (members only). Serving is storage-aware:
     *   - s3 (or any disk supporting temporary URLs): redirect to a short-lived
     *     (5-minute) presigned URL, so the bytes never proxy through the app;
     *   - local (and other non-presignable disks): stream the file from the
     *     PRIVATE disk as a Content-Disposition: attachment download.
     *
     * The stored path is a framework-generated disk key (never user input); we
     * still reject any traversal segment defensively before serving.
     */
    public function downloadAttachment(Request $request, Project $project, Task $task, Attachment $attachment): RedirectResponse|StreamedResponse
    {
        $this->assertTaskInProject($project, $task);

        if ((int) $attachment->task_id !== (int) $task->getKey()) {
            throw new NotFoundHttpException('No such attachment on this task.');
        }

        $this->authorize('view', $task);

        $path = (string) $attachment->path;
        if ($path === '' || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new NotFoundHttpException('Invalid attachment path.');
        }

        // Storage-aware: prefer a short-lived presigned URL when the active disk
        // supports it (s3). Local/memory disks throw, so we fall back to a
        // direct stream from the private disk.
        try {
            $url = Storage::temporaryUrl($path, new DateTimeImmutable('+5 minutes'));

            return redirect($url);
        } catch (RuntimeException) {
            return Storage::download($path, (string) $attachment->original_name);
        }
    }

    /**
     * Store an uploaded `attachment` (when present) for the task, recording an
     * Attachment row. Returns a human error string on rejection (oversize,
     * disallowed extension, or content/extension mismatch) — nothing is written
     * in any of those cases — or null on success / when no file was sent.
     *
     * Validation mirrors IonUpload (the framework UploadValidator: allow-list +
     * active-content deny-list + magic-bytes agreement) BUT the validated file
     * is written through the Storage abstraction onto the PRIVATE default disk
     * (var/storage for local; the bucket for s3), so attachments are never
     * placed under the public web root. The recorded path is the disk key.
     */
    private function storeAttachment(Request $request, Task $task): ?string
    {
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        // Cheap size gate first.
        if ((int) $file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            return 'Attachment is too large (max 2 MiB).';
        }

        // Same validation engine IonUpload uses: extension allow-list (with the
        // active-content deny-list) AND magic-bytes agreement, BEFORE any write.
        $validator = new UploadValidator(self::ALLOWED_EXTENSIONS, (array) config('app.uploads.mime_map', []));
        $originalName = $file->getClientOriginalName();

        if (!$validator->isAllowed($originalName)) {
            return 'Attachment rejected: file extension not allowed';
        }

        if (!$validator->isContentValid($file->getPathname(), $originalName)) {
            return 'Attachment rejected: file content does not match its extension';
        }

        $size = (int) $file->getSize();

        // Write onto the PRIVATE default disk under attachments/. Storage::fake()
        // intercepts this onto the in-memory disk in tests. Returns the disk key.
        $path = Storage::putFile(self::ATTACHMENT_DIR, $file);

        Attachment::query()->create([
            'task_id' => $task->getKey(),
            'path' => $path,
            'original_name' => $originalName,
            'size' => $size,
        ]);

        return null;
    }

    /** A bound task must belong to the bound project, else a 404. */
    private function assertTaskInProject(Project $project, Task $task): void
    {
        if ((int) $task->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException('No task in this project.');
        }
    }
}
