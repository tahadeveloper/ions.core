<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Ions\Foundation\ApiController;
use Ions\Http\Resource;
use Ions\Http\ResourceCollection;

/**
 * JSON API for projects (behind AuthMiddleware/JWT — the Bearer token resolves
 * App\Models\User onto the request). Returns 7.7 Resource envelopes:
 *
 * - index — the authenticated user's projects as a paginated ResourceCollection
 *           (data + meta + links).
 * - show  — route-model-bound Project, gated by ProjectPolicy::view (403 for a
 *           non-member); a single-`data` ProjectResource.
 * - store — StoreProjectRequest validation (422 on bad input, JSON bag); the
 *           token user owns the new project; a single-`data` ProjectResource.
 */
final class ProjectApiController extends ApiController
{
    public function index(): ResourceCollection
    {
        $user = $this->user();

        $projects = Project::query()
            ->where(fn ($q) => $q
                ->where('owner_id', $user->getKey())
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->getKey())))
            ->orderByDesc('id')
            ->paginate(5);

        return ProjectResource::collection($projects);
    }

    public function show(Project $project): Resource
    {
        $this->authorize('view', $project);

        return ProjectResource::make($project);
    }

    public function store(): Resource
    {
        $data = StoreProjectRequest::validate($this->request);
        $user = $this->user();

        $project = Project::query()->create([
            'owner_id' => $user->getKey(),
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
        ]);

        return ProjectResource::make($project);
    }

    /** The JWT-resolved user (App\Models\User) on the request. */
    private function user(): User
    {
        /** @var User $user */
        $user = $this->request->attributes->get('auth_user');

        return $user;
    }
}
