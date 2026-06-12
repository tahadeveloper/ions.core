<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Resources\TaskResource;
use App\Jobs\SendTaskAssignedNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Ions\Foundation\ApiController;
use Ions\Http\Resource;
use Ions\Http\ResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON API for tasks, nested under a project (behind AuthMiddleware/JWT).
 * The {project} placeholder is route-model-bound; the project's policy gates
 * access (403 for a non-member). Returns 7.7 Resource envelopes; store()
 * validates via StoreTaskRequest (422 on bad input).
 */
final class TaskApiController extends ApiController
{
    public function index(Project $project): ResourceCollection
    {
        $this->authorize('view', $project);

        $tasks = $project->tasks()->orderByDesc('id')->paginate(10);

        return TaskResource::collection($tasks);
    }

    public function show(Project $project, Task $task): Resource
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('view', $task);

        return TaskResource::make($task);
    }

    public function store(Project $project): Resource
    {
        $this->authorize('update', $project);
        $data = StoreTaskRequest::validate($this->request);

        $task = Task::query()->create([
            'project_id' => $project->getKey(),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? Task::STATUS_TODO,
        ]);

        return TaskResource::make($task);
    }

    /**
     * Assign the task to a project member, then dispatch the
     * SendTaskAssignedNotification job (which notifies the assignee on the
     * mail + database channels). 422 if the `assignee` is not a project member.
     */
    public function assign(Project $project, Task $task): Resource
    {
        $this->assertTaskInProject($project, $task);
        $this->authorize('update', $task);

        $assigneeId = (int) $this->request->request->get('assignee');
        $assignee = User::query()->find($assigneeId);

        $isMember = $assignee !== null && (
            (int) $project->owner_id === $assignee->getKey()
            || $project->members()->whereKey($assignee->getKey())->exists()
        );

        if (!$isMember) {
            throw new HttpException(422, 'Assignee must be a project member.');
        }

        $task->update(['assignee_id' => $assignee->getKey()]);

        dispatch(new SendTaskAssignedNotification($task->getKey(), $assignee->getKey()));

        return TaskResource::make($task->refresh());
    }

    /** A bound task must belong to the bound project, else a 404. */
    private function assertTaskInProject(Project $project, Task $task): void
    {
        if ((int) $task->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException('No task in this project.');
        }
    }
}
