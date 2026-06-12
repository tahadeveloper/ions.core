<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Web CRUD for projects (behind ['web.auth', 'verified']).
 *
 * - index   — projects the logged-in user owns OR is a member of, paginated
 *             (10.3 paginate() + the pagination() Twig function, query-string
 *             preserved across page links).
 * - show    — route model binding (10.2: the `Project $project` param matches
 *             the `{project}` placeholder, fetched by route key, 404 on miss),
 *             then $this->authorize('view', $project) (ProjectPolicy via Gate).
 * - store   — StoreProjectRequest validates (web fail → redirect-back + old +
 *             errors); the owner is the session user; success flashes 'status'.
 * - update  — binding + authorize('update'); destroy — binding + authorize
 *             ('delete', owner-only per ProjectPolicy).
 *
 * $viewPath pins the view folder to views/projects/ (the FQCN-derived default
 * would be 'project' singular; this keeps the plural convention from the task).
 */
final class ProjectController extends BaseController
{
    protected string $viewPath = 'projects';

    public function index(Request $request): View
    {
        $user = $this->authUser($request);

        // Owned + member projects, newest first. paginate() reads ?page from the
        // request via the DatabaseProvider's currentPageResolver.
        $projects = Project::query()
            ->where('owner_id', $user->getKey())
            ->orWhereHas('members', fn ($q) => $q->where('users.id', $user->getKey()))
            ->orderByDesc('id')
            ->paginate(5);

        return $this->view('index', [
            'projects' => $projects,
            'status' => flash('status'),
            'error' => flash('error'),
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return $this->view('show', [
            'project' => $project,
            'tasks' => $project->tasks()->orderByDesc('id')->get(),
            'status' => flash('status'),
            'error' => flash('error'),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->view('create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = StoreProjectRequest::validate($request);
        $user = $this->authUser($request);

        $project = Project::query()->create([
            'owner_id' => $user->getKey(),
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
        ]);

        return redirect('/projects/' . $project->getKey())
            ->with('status', 'Project created.');
    }

    public function edit(Request $request, Project $project): View
    {
        $this->authorize('update', $project);

        return $this->view('edit', ['project' => $project]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = UpdateProjectRequest::validate($request);

        $project->update([
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
        ]);

        return redirect('/projects/' . $project->getKey())
            ->with('status', 'Project updated.');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);
        $project->delete();

        return redirect('/projects')->with('status', 'Project deleted.');
    }

    /** The session user published onto the request by SessionAuthMiddleware. */
    private function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        return $user;
    }
}
