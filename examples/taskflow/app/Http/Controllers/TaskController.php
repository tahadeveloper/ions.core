<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use Ions\Bundles\IonUpload;
use Ions\Bundles\Path;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;
use Ions\View\View;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
 * store()/update() accept an optional `attachment` file: it is stored through
 * IonUpload::store() — magic-bytes + extension allow-list validated, traversal
 * targets refused — and an Attachment row records the relative path. Disallowed
 * types (e.g. .svg/.php) and oversize files are rejected without writing, and
 * the user is redirected back with an error.
 */
final class TaskController extends BaseController
{
    protected string $viewPath = 'tasks';

    /** Relative uploads sub-directory for task attachments. */
    private const ATTACHMENT_DIR = 'attachments';

    /** Hard cap on an accepted attachment (bytes). */
    private const MAX_ATTACHMENT_BYTES = 2_097_152; // 2 MiB

    public function show(Request $request, Project $project, Task $task): View
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('view', $task);

        return $this->view('show', [
            'project' => $project,
            'task' => $task,
            'attachments' => $task->attachments()->orderByDesc('id')->get(),
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

        $task->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? $task->status,
        ]);

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
     * Store an uploaded `attachment` (when present) for the task, recording an
     * Attachment row. Returns a human error string on rejection (oversize, or
     * IonUpload's validation: disallowed extension, content/extension mismatch,
     * traversal target) — nothing is written outside the uploads root in any of
     * those cases — or null on success / when no file was sent.
     */
    private function storeAttachment(Request $request, Task $task): ?string
    {
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return null;
        }

        // Cheap size gate before touching IonUpload.
        if ((int) $file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            return 'Attachment is too large (max 2 MiB).';
        }

        $size = (int) $file->getSize();

        // Path::files() resolves the absolute uploads sub-dir (containment
        // enforced); IonUpload validates magic-bytes + the allow-list and, under
        // Storage::fake(), the write is intercepted onto the fake disk.
        $result = IonUpload::store($file, Path::files(self::ATTACHMENT_DIR))->response();

        if ((int) ($result['error'] ?? 1) !== 0) {
            return 'Attachment rejected: ' . ($result['message'] ?? 'invalid file');
        }

        Attachment::query()->create([
            'task_id' => $task->getKey(),
            'path' => self::ATTACHMENT_DIR . '/' . $result['store_name'],
            'original_name' => $result['original_name'],
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
