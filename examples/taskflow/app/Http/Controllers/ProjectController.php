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
        // The owner-or-member condition is grouped so a future added filter
        // (e.g. ->where('status', ...)) can't leak rows via OR precedence.
        $projects = Project::query()
            ->where(fn ($q) => $q
                ->where('owner_id', $user->getKey())
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->getKey())))
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
            // The note is encrypted at rest; decrypt it here for the authorized
            // viewer (authorize('view') already passed). noteText() returns null
            // on a DecryptException, so the page never breaks on a bad value.
            'note' => $project->noteText(),
            'tasks' => $project->tasks()->orderByDesc('id')->get(),
            'shareUrl' => flash('shareUrl'),
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
            // Encrypt the note at rest (13.6): the column holds ciphertext only.
            'note' => Project::encryptNote($data['note'] ?? null),
        ]);

        return redirect('/projects/' . $project->getKey())
            ->with('status', 'Project created.');
    }

    public function edit(Request $request, Project $project): View
    {
        $this->authorize('update', $project);

        // The form pre-fills the decrypted note (encrypted at rest, 13.6).
        return $this->view('edit', ['project' => $project, 'note' => $project->noteText()]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = UpdateProjectRequest::validate($request);

        $project->update([
            'name' => $data['name'],
            'note' => Project::encryptNote($data['note'] ?? null),
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
