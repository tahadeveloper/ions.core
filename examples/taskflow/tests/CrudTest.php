<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow CRUD surface (13.4)
|--------------------------------------------------------------------------
| Exercises projects/tasks CRUD over the public framework APIs: route model
| binding (10.2), FormRequest web redirect-back with errors/old (10.3),
| pagination (paginate + the pagination() Twig function), attachment uploads
| (IonUpload magic-bytes + allow-list, Storage::fake interception), and the
| JSON API Resource envelope. Web routes are gated by ['web.auth','verified'],
| so each web test logs a verified user into the array session first; API
| routes go through JWT (actingAs issues a real Bearer token).
|
| CSRF is disabled for the web POSTs (the framework form-flow pattern, mirrored
| from AuthTest); the array session persists across sequential handle() calls
| in one process so a session login is visible to the next request.
*/

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Ions\Filesystem\Storage;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    cache()->flush();
});

/** Log a verified user into the array web session (what web.auth reads). */
function loginWeb(User $user): void
{
    session()->put('auth_user_id', $user->getKey());
}

/** Minimal valid PNG payload (finfo => image/png) for content-validation. */
function tinyPngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

/** Build an in-memory UploadedFile (test mode bypasses is_uploaded_file). */
function makeUpload(string $clientName, string $content): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'cf');
    file_put_contents($tmp, $content);

    return new UploadedFile($tmp, $clientName, null, null, true);
}

// --- Web: create project (validation, redirect, flash) ----------------------

test('a valid web create persists the project, redirects, and flashes success', function () {
    $user = User::factory()->create();
    loginWeb($user);

    $response = $this->post('/projects', ['name' => 'Apollo', 'note' => 'moon shot']);

    $project = Project::query()->where('name', 'Apollo')->first();
    expect($project)->not->toBeNull()
        ->and((int) $project->owner_id)->toBe($user->getKey());

    $response->assertRedirect('http://localhost:8000/projects/' . $project->getKey());
});

test('an invalid web create redirects back with errors and preserves old input', function () {
    $user = User::factory()->create();
    loginWeb($user);

    // Missing required `name`; `note` should survive as old input.
    $this->post('/projects', ['name' => '', 'note' => 'kept'], [
        'HTTP_REFERER' => 'http://localhost:8000/projects/create',
    ])->assertRedirect('http://localhost:8000/projects/create');

    expect(Project::query()->count())->toBe(0);

    // The redirect-back flashed the bag + old input; the create form renders them.
    $form = $this->get('/projects/create');
    $form->assertOk();
    expect($form->content())->toContain('kept')          // old('note')
        ->and($form->content())->toContain('class="errors"');
});

// --- Web: route model binding 404 + policy 403 ------------------------------

test('binding 404s on a missing project', function () {
    $user = User::factory()->create();
    loginWeb($user);

    $this->get('/projects/999999')->assertStatus(404);
});

test('the policy 403s a non-member viewing a project', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    loginWeb($outsider);
    $this->get('/projects/' . $project->getKey())->assertStatus(403);

    // The owner is allowed through.
    loginWeb($owner);
    $this->get('/projects/' . $project->getKey())->assertOk();
});

// --- Web: pagination --------------------------------------------------------

test('the project index paginates and preserves the query string across pages', function () {
    $user = User::factory()->create();
    loginWeb($user);

    // 7 projects, 5 per page => 2 pages.
    Project::factory()->count(7)->create(['owner_id' => $user->getKey()]);

    $page1 = $this->get('/projects?sort=name');
    $page1->assertOk();
    // pagination() rendered a link to page 2, preserving ?sort=name.
    expect($page1->content())->toContain('page=2')
        ->and($page1->content())->toContain('sort=name');

    // Page 2 resolves and renders the remaining 2 projects.
    $page2 = $this->get('/projects?page=2&sort=name');
    $page2->assertOk();
    expect($page2->content())->toContain('page=1'); // back-link to page 1
});

// --- Web: task create with attachment (Storage::fake) -----------------------

test('a task create with a valid attachment stores the file and records an Attachment row', function () {
    $fake = Storage::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginWeb($owner);

    $file = makeUpload('diagram.png', tinyPngBytes());

    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Design', 'status' => 'todo'],
        [],
        ['attachment' => $file],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Design')->first();
    expect($task)->not->toBeNull();

    $attachment = Attachment::query()->where('task_id', $task->getKey())->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->original_name)->toBe('diagram.png')
        ->and($attachment->path)->toStartWith('attachments/');

    // The file landed on the FAKE (private) disk at exactly the recorded
    // disk-relative key — under attachments/, NOT the public web root.
    $fake->assertStored((string) $attachment->path);
});

// --- Web: attachment rejection (no write outside uploads) -------------------

test('a disallowed attachment type is rejected without recording an Attachment', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginWeb($owner);

    // SVG is an active-content type and is NOT on the allow-list (12.1 deny).
    $svg = makeUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Bad upload', 'status' => 'todo'],
        [],
        ['attachment' => $svg],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey()],
    )->assertRedirect();

    // The task was created, but the rejected upload produced no Attachment row.
    $task = Task::query()->where('title', 'Bad upload')->first();
    expect($task)->not->toBeNull()
        ->and(Attachment::query()->where('task_id', $task->getKey())->count())->toBe(0);
});

test('a traversal-named attachment is rejected and writes nothing', function () {
    $fake = Storage::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginWeb($owner);

    // A traversal payload in the client filename: UploadValidator reduces it to
    // a bare safe name AND the extension (".env" / no allowed ext) is rejected,
    // so nothing is stored.
    $evil = makeUpload('../../../../.env', 'SECRET=1');

    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Traversal', 'status' => 'todo'],
        [],
        ['attachment' => $evil],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey()],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Traversal')->first();
    expect(Attachment::query()->where('task_id', $task->getKey())->count())->toBe(0);

    // Nothing escaped the uploads dir onto the fake disk.
    $fake->assertMissing('.env');
});

test('an oversize attachment is rejected without recording an Attachment', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginWeb($owner);

    // 2 MiB + 1 byte of valid PNG-prefixed content: over the controller's cap.
    $big = makeUpload('huge.png', tinyPngBytes() . str_repeat('x', 2_097_152));

    $this->call('POST', '/projects/' . $project->getKey() . '/tasks',
        ['title' => 'Oversize', 'status' => 'todo'],
        [],
        ['attachment' => $big],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey()],
    )->assertRedirect();

    $task = Task::query()->where('title', 'Oversize')->first();
    expect(Attachment::query()->where('task_id', $task->getKey())->count())->toBe(0);
});

// --- API: Resource envelope shape -------------------------------------------

test('the API project index returns a paginated Resource collection envelope', function () {
    $owner = User::factory()->create();
    Project::factory()->count(2)->create(['owner_id' => $owner->getKey()]);

    $response = $this->actingAs($owner)->json('GET', '/api/projects');

    $response->assertOk()
        ->assertJsonPath('meta.per_page', 5);
    expect($response->json('data'))->toBeArray()->toHaveCount(2)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'note', 'owner_id'])
        ->and($response->json('links'))->toHaveKeys(['first', 'last', 'prev', 'next']);
});

test('the API project show returns a single data envelope and 403s a non-member', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $this->actingAs($owner)
        ->json('GET', '/api/projects/' . $project->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $project->getKey())
        ->assertJsonPath('data.name', $project->name);

    $this->actingAs($outsider)
        ->json('GET', '/api/projects/' . $project->getKey())
        ->assertStatus(403);
});

// --- API: store validation + policy -----------------------------------------

test('the API project store 422s on bad input', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->json('POST', '/api/projects', ['name' => ''])
        ->assertStatus(422);
});

test('the API task store 403s a non-member and 422s on bad input for a member', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    // Non-member cannot add a task.
    $this->actingAs($outsider)
        ->json('POST', '/api/projects/' . $project->getKey() . '/tasks', ['title' => 'X'])
        ->assertStatus(403);

    // Owner with bad input gets a 422.
    $this->actingAs($owner)
        ->json('POST', '/api/projects/' . $project->getKey() . '/tasks', ['title' => ''])
        ->assertStatus(422);

    // Owner with valid input gets the task Resource.
    $this->actingAs($owner)
        ->json('POST', '/api/projects/' . $project->getKey() . '/tasks', ['title' => 'Ship it'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Ship it')
        ->assertJsonPath('data.status', 'todo');
});
