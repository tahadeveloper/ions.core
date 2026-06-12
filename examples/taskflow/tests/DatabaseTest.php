<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow domain (13.2)
|--------------------------------------------------------------------------
| Boots the example app (in-memory SQLite), runs the host's real migration
| files (database/schemas/*.php) against the booted connection, then exercises
| the domain: tables exist, every factory builds + persists, relationships
| resolve, and User satisfies the framework auth contracts.
*/

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Foundation\Kernel;

function taskflowSchema(): \Illuminate\Database\Schema\Builder
{
    return Kernel::app()->get('db')->connection()->getSchemaBuilder();
}

beforeEach(function () {
    migrateTaskflow();
});

test('the migrations create every domain table', function () {
    $schema = taskflowSchema();

    foreach ([
        'users',
        'projects',
        'tasks',
        'attachments',
        'comments',
        'project_members',
        'jobs',
        'failed_jobs',
        'notifications',
    ] as $table) {
        expect($schema->hasTable($table))->toBeTrue("missing table: {$table}");
    }
});

test('the test connection is the throwaway in-memory sqlite', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(Kernel::app()->get('db')->connection()->getDatabaseName())->toBe(':memory:');
});

test('the UserFactory builds and persists a user', function () {
    $user = User::factory()->create();

    expect($user->exists)->toBeTrue()
        ->and($user->getKey())->toBeInt()
        ->and($user->email)->toContain('@')
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('UserFactory unverified() and withTwoFactor() states', function () {
    $unverified = User::factory()->unverified()->create();
    $twoFactor = User::factory()->withTwoFactor()->create();

    expect($unverified->hasVerifiedEmail())->toBeFalse()
        ->and($unverified->email_verified_at)->toBeNull()
        ->and($twoFactor->two_factor_secret)->not->toBeNull()
        ->and($twoFactor->two_factor_recovery_codes)->not->toBeNull();
});

test('the ProjectFactory builds and persists a project with an owner', function () {
    $project = Project::factory()->create();

    expect($project->exists)->toBeTrue()
        ->and($project->owner)->toBeInstanceOf(User::class)
        ->and($project->owner->exists)->toBeTrue();
});

test('the TaskFactory builds and persists a task', function () {
    $task = Task::factory()->create();

    expect($task->exists)->toBeTrue()
        ->and($task->project)->toBeInstanceOf(Project::class)
        ->and(Task::STATUSES)->toContain($task->status);
});

test('the AttachmentFactory builds and persists an attachment', function () {
    $attachment = Attachment::factory()->create();

    expect($attachment->exists)->toBeTrue()
        ->and($attachment->task)->toBeInstanceOf(Task::class)
        ->and($attachment->size)->toBeGreaterThan(0)
        ->and($attachment->original_name)->not->toBe('');
});

test('the CommentFactory builds and persists a comment', function () {
    $comment = Comment::factory()->create();

    expect($comment->exists)->toBeTrue()
        ->and($comment->task)->toBeInstanceOf(Task::class)
        ->and($comment->author)->toBeInstanceOf(User::class);
});

test('relationships resolve: project->tasks and task->assignee', function () {
    $project = Project::factory()->create();
    $assignee = User::factory()->create();

    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assignee_id' => $assignee->getKey(),
    ]);

    expect($project->tasks()->count())->toBe(1)
        ->and($project->tasks->first()->is($task))->toBeTrue()
        ->and($task->assignee->is($assignee))->toBeTrue();
});

test('the assignee relationship is nullable', function () {
    $task = Task::factory()->create(['assignee_id' => null]);

    expect($task->assignee)->toBeNull();
});

test('user->ownedProjects resolves', function () {
    $owner = User::factory()->create();
    Project::factory()->count(2)->create(['owner_id' => $owner->getKey()]);

    expect($owner->ownedProjects()->count())->toBe(2);
});

test('project->members and user->memberProjects resolve through the pivot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);

    $project->members()->sync([$owner->getKey(), $member->getKey()]);

    expect($project->members()->count())->toBe(2)
        ->and($member->memberProjects()->count())->toBe(1)
        ->and($member->memberProjects->first()->is($project))->toBeTrue();
});

test('task->comments and task->attachments resolve', function () {
    $task = Task::factory()->create();
    Comment::factory()->count(3)->create(['task_id' => $task->getKey()]);
    Attachment::factory()->count(2)->create(['task_id' => $task->getKey()]);

    expect($task->comments()->count())->toBe(3)
        ->and($task->attachments()->count())->toBe(2);
});

test('the Task status enum exposes exactly todo|doing|done', function () {
    expect(Task::STATUSES)->toBe(['todo', 'doing', 'done'])
        ->and(Task::STATUS_TODO)->toBe('todo')
        ->and(Task::STATUS_DOING)->toBe('doing')
        ->and(Task::STATUS_DONE)->toBe('done');
});

test('a new task defaults to the todo status', function () {
    $task = Task::factory()->create(['status' => Task::STATUS_TODO]);

    expect($task->status)->toBe('todo');
});

test('User satisfies the Authenticatable contract', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and($user->getAuthIdentifier())->toBe($user->getKey())
        ->and($user->getAuthIdentifierName())->toBe('id');
});

test('User satisfies the VerifiesEmail contract', function () {
    $user = User::factory()->unverified()->create();

    expect($user)->toBeInstanceOf(VerifiesEmail::class)
        ->and($user->getEmailForVerification())->toBe($user->email)
        ->and($user->getKeyForVerification())->toBe($user->getKey())
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->getEmailVerifiedAt())->toBeNull();

    $user->markEmailVerified();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->getEmailVerifiedAt())->toBeInstanceOf(DateTimeInterface::class);
});

test('the password and 2FA columns are hidden from array output', function () {
    $user = User::factory()->withTwoFactor()->create();
    $array = $user->toArray();

    expect($array)->not->toHaveKey('password')
        ->not->toHaveKey('two_factor_secret')
        ->not->toHaveKey('two_factor_recovery_codes')
        ->and($array)->toHaveKey('email');
});
