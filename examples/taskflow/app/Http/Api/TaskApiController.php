<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use Ions\Foundation\ApiController;
use Ions\Http\Resource;
use Ions\Http\ResourceCollection;
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

    /** A bound task must belong to the bound project, else a 404. */
    private function assertTaskInProject(Project $project, Task $task): void
    {
        if ((int) $task->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException('No task in this project.');
        }
    }
}
