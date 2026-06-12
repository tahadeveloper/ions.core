<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow sharing / caching / http / encryption (13.6)
|--------------------------------------------------------------------------
| Exercises the public framework primitives wired in 13.6:
|   - signedRoute() + 'signed' middleware (expiring share + unsubscribe links;
|     tampered/expired → 403)
|   - ResponseCache + 'cache.response' (anonymous public board cached; an
|     authenticated view is NEVER cached)
|   - Encrypter (Project.note encrypted at rest, decrypted for authorized users)
|   - Http client + Http::fake() (task-completion webhook)
|
| The public board is a PLAIN controller (no auth) returning a normal View. It
| prints no CSRF token / no form, so the lazy `_csrf_token` global (core 4.6)
| never writes the session — the response stays anonymous and cacheable through
| the framework view layer. The cache middleware bypasses on APP_DEBUG, so the
| caching tests force APP_DEBUG=false for the duration of the request.
*/

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Ions\Security\Encrypter;
use Ions\Support\Http;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    cache()->flush();
});

/** Log a verified user into the array web session (what web.auth reads). */
function loginShare(User $user): void
{
    session()->put('auth_user_id', $user->getKey());
}

/** Extract the request URI (path + query) from an absolute app URL. */
function uriOf(string $absoluteUrl): string
{
    $parts = parse_url($absoluteUrl);

    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

// --- Signed share link generation -------------------------------------------

test('the owner can mint a signed, expiring share link for the public board', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    loginShare($owner);

    $this->post('/projects/' . $project->getKey() . '/share')
        ->assertRedirect('http://localhost:8000/projects/' . $project->getKey());

    // The flashed link points at share.board, carries a signature + expiry.
    $url = flash('shareUrl');
    expect($url)->toBeString()
        ->and($url)->toContain('/share/board/' . $project->getKey())
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('expires=');
});

test('a non-owner cannot mint a share link', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    loginShare($outsider);
    $this->post('/projects/' . $project->getKey() . '/share')->assertStatus(403);
});

// --- Public board: valid link renders read-only, no auth --------------------

test('a valid signed link renders the public read-only board with its tasks (no auth)', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey(), 'name' => 'Apollo']);
    Task::factory()->create(['project_id' => $project->getKey(), 'title' => 'Land the module', 'status' => 'doing']);

    $url = signedRoute('share.board', ['project' => $project->getKey()], time() + 3600);

    // No session/login at all — anonymous request.
    $response = $this->get(uriOf($url));
    $response->assertOk();
    expect($response->content())->toContain('Apollo')
        ->toContain('Land the module')
        ->toContain('Read-only shared board');
});

// --- Public board: tampered + expired → 403 ---------------------------------

test('a tampered signature is rejected with a 403', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $url = signedRoute('share.board', ['project' => $project->getKey()], time() + 3600);
    // Flip the last char of the signature → HMAC mismatch.
    $tampered = preg_replace_callback('/signature=([0-9a-f]+)/', static function ($m) {
        $sig = $m[1];
        $last = substr($sig, -1) === 'a' ? 'b' : 'a';

        return 'signature=' . substr($sig, 0, -1) . $last;
    }, $url);

    $this->get(uriOf($tampered))->assertStatus(403);
});

test('an expired link is rejected with a 403', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    // Signed with an expiry one second in the past.
    $url = signedRoute('share.board', ['project' => $project->getKey()], time() - 1);

    $this->get(uriOf($url))->assertStatus(403);
});

// --- Response cache: anonymous board cached; authed view never cached --------

test('the public board is cached on the second anonymous hit', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $url = signedRoute('share.board', ['project' => $project->getKey()], time() + 3600);
    $uri = uriOf($url);

    // The cache middleware bypasses entirely on APP_DEBUG.
    $original = $_ENV['APP_DEBUG'] ?? null;
    $_ENV['APP_DEBUG'] = 'false';
    config(['cache.response.enabled' => true]);

    try {
        $first = $this->get($uri);
        $first->assertOk();
        expect($first->headers()->get('X-Ions-Cache'))->toBe('MISS');

        $second = $this->get($uri);
        $second->assertOk();
        expect($second->headers()->get('X-Ions-Cache'))->toBe('HIT');

        // A cached response carries no Set-Cookie (anonymous, shareable).
        expect($second->headers()->get('Set-Cookie'))->toBeNull();
    } finally {
        if ($original === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $original;
        }
    }
});

test('the cache.response route never serves a cached HIT to an authenticated session', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    // SAME cache.response route as the anonymous test, but now with a logged-in
    // web session. ResponseCache::requestIsStateful() sees the session user, so
    // the response is treated as per-user and is never stored — no MISS-store,
    // no later HIT. This is the "never cache an authenticated view" guarantee.
    loginShare($owner);

    $url = signedRoute('share.board', ['project' => $project->getKey()], time() + 3600);
    $uri = uriOf($url);

    $original = $_ENV['APP_DEBUG'] ?? null;
    $_ENV['APP_DEBUG'] = 'false';
    config(['cache.response.enabled' => true]);

    try {
        $first = $this->get($uri);
        $first->assertOk();
        // Stateful request => never stored, so it is NOT stamped MISS either.
        expect($first->headers()->get('X-Ions-Cache'))->not->toBe('MISS');

        $second = $this->get($uri);
        $second->assertOk();
        expect($second->headers()->get('X-Ions-Cache'))->not->toBe('HIT');
    } finally {
        if ($original === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $original;
        }
    }
});

// --- Encrypter: note encrypted at rest, decrypted for the owner -------------

test('the project note is stored encrypted and shown decrypted to the owner', function () {
    $owner = User::factory()->create();
    loginShare($owner);

    $this->post('/projects', ['name' => 'Secret', 'note' => 'top secret plan'])
        ->assertRedirect();

    $project = Project::query()->where('name', 'Secret')->first();
    expect($project)->not->toBeNull();

    // The raw column is ciphertext (versioned 'iev1:' payload), not the plaintext.
    expect((string) $project->note)->toStartWith('iev1:')
        ->not->toContain('top secret plan');

    // It round-trips through the Encrypter / model accessor.
    expect($project->noteText())->toBe('top secret plan');

    // And the owner's show page renders the decrypted note.
    $show = $this->get('/projects/' . $project->getKey());
    $show->assertOk();
    expect($show->content())->toContain('top secret plan');
});

test('the stored note decrypts under the framework Encrypter', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'owner_id' => $owner->getKey(),
        'note' => Project::encryptNote('classified'),
    ]);

    $encrypter = Encrypter::fromAppKey((string) env('APP_KEY'));
    expect($encrypter->decrypt((string) $project->note))->toBe('classified');
});

test('a non-member cannot reach the project and so never sees the note', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $project = Project::factory()->create([
        'owner_id' => $owner->getKey(),
        'note' => Project::encryptNote('members only'),
    ]);

    loginShare($outsider);
    // ProjectPolicy::view denies a non-member (403) before any note is rendered.
    $this->get('/projects/' . $project->getKey())->assertStatus(403);
});

// --- Signed unsubscribe -----------------------------------------------------

test('a signed unsubscribe link flips the opt-out flag', function () {
    $user = User::factory()->create(['notifications_opt_out' => false]);

    $url = signedRoute('share.unsubscribe', ['user' => $user->getKey()]);

    $this->get(uriOf($url))->assertRedirect();

    expect($user->fresh()->notifications_opt_out)->toBeTrue();
});

test('a tampered unsubscribe link is rejected and does not flip the flag', function () {
    $user = User::factory()->create(['notifications_opt_out' => false]);

    $url = signedRoute('share.unsubscribe', ['user' => $user->getKey()]);
    $tampered = preg_replace_callback('/signature=([0-9a-f]+)/', static function ($m) {
        $sig = $m[1];
        $last = substr($sig, -1) === 'a' ? 'b' : 'a';

        return 'signature=' . substr($sig, 0, -1) . $last;
    }, $url);

    $this->get(uriOf($tampered))->assertStatus(403);
    expect($user->fresh()->notifications_opt_out)->toBeFalse();
});

// --- Http client: completion webhook via Http::fake() -----------------------

test('marking a task done fires the completion webhook (Http::fake)', function () {
    Http::fake(['*' => 'ok']);

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Ship',
        'status' => 'doing',
    ]);
    loginShare($owner);

    $this->post('/projects/' . $project->getKey() . '/tasks/' . $task->getKey(), [
        'title' => 'Ship',
        'status' => 'done',
    ])->assertRedirect();

    expect($task->fresh()->status)->toBe('done');

    // The webhook went out exactly once to the completion endpoint.
    Http::assertSent('https://hooks.taskflow.test/task-completed');
    Http::assertSentCount(1);
});

test('a non-done task update does not fire the webhook', function () {
    Http::fake(['*' => 'ok']);

    $owner = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Ship',
        'status' => 'todo',
    ]);
    loginShare($owner);

    $this->post('/projects/' . $project->getKey() . '/tasks/' . $task->getKey(), [
        'title' => 'Ship',
        'status' => 'doing',
    ])->assertRedirect();

    Http::assertNothingSent();
});
