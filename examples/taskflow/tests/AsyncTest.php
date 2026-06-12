<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Taskflow async surface (13.5)
|--------------------------------------------------------------------------
| Exercises jobs, notifications, mail and the scheduler over the public
| framework APIs:
|   - assigning a task (web + api) dispatches SendTaskAssignedNotification
|     (Queue::fake assertDispatched);
|   - the job notifies the assignee → Mail::fake assertSent + Notifications::fake
|     assertSentTo + a database-channel notifications row;
|   - the in-app notification list shows the user's rows;
|   - the FlakyDemoJob ($tries=2) lands in failed_jobs exactly once when run
|     through the in-process queue:work worker against the database driver, and
|     queue:retry re-pushes it (mirrors the core QueueFailedJobsTest);
|   - schedule:run / the scheduler runs a due task at a frozen now;
|   - the WelcomeMail / DigestMail mailables build with the right content;
|   - the taskflow:demo-seed command runs.
*/

use App\Jobs\FlakyDemoJob;
use App\Jobs\SendTaskAssignedNotification;
use App\Mail\DigestMail;
use App\Mail\WelcomeMail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Schedule;
use Ions\Foundation\Kernel;
use Ions\Support\Mail;
use Ions\Support\Notifications;
use Ions\Support\Queue;
use Ions\Support\Schedule as ScheduleFacade;

beforeEach(function () {
    migrateTaskflow();
    config(['app.csrf.enabled' => false]);
    cache()->flush();
});

/** Log a verified user into the array web session (what web.auth reads). */
function loginAsync(User $user): void
{
    session()->put('auth_user_id', $user->getKey());
}

/** A project owned by $owner with $member as a member. */
function projectWithMember(User $owner, User $member): Project
{
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    $project->members()->sync([$owner->getKey(), $member->getKey()]);

    return $project;
}

/** Query-builder shortcut for a queue table on the test connection. */
function asyncTable(string $table): \Illuminate\Database\Query\Builder
{
    return Kernel::app()->get('db')->connection()->table($table);
}

/** Run queue:work --once against the database connection (mirrors core). */
function asyncWorkOnce(array $options = []): \Symfony\Component\Console\Tester\CommandTester
{
    $proxy = new \Ions\Console\ConsoleContainerProxy(Kernel::app());
    $app = new \Illuminate\Console\Application($proxy, new \Illuminate\Events\Dispatcher(), '1.0');
    $command = new \QueueWorkCommand();
    $app->add($command);

    $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find($command->getName() ?? ''));
    $tester->execute(array_merge(['connection' => 'database', '--once' => true], $options));

    return $tester;
}

/** Run any framework console command in-process against the booted container. */
function asyncRunConsole(\Illuminate\Console\Command $command, array $input = []): \Symfony\Component\Console\Tester\CommandTester
{
    $proxy = new \Ions\Console\ConsoleContainerProxy(Kernel::app());
    $app = new \Illuminate\Console\Application($proxy, new \Illuminate\Events\Dispatcher(), '1.0');
    $app->add($command);

    $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find($command->getName() ?? ''));
    $tester->execute($input);

    return $tester;
}

// --- Assignment dispatches the job ------------------------------------------

test('assigning a task dispatches the notification job (Queue::fake)', function () {
    $fake = Queue::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = projectWithMember($owner, $member);
    $task = Task::factory()->create(['project_id' => $project->getKey()]);

    loginAsync($owner);

    $this->post(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/assign',
        ['assignee' => $member->getKey()]
    )->assertRedirect();

    $fake->assertDispatched(SendTaskAssignedNotification::class);
});

test('assigning a task over the API dispatches the notification job', function () {
    $fake = Queue::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = projectWithMember($owner, $member);
    $task = Task::factory()->create(['project_id' => $project->getKey()]);

    $this->actingAs($owner);

    $this->post(
        '/api/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/assign',
        ['assignee' => $member->getKey()]
    )->assertOk();

    $fake->assertDispatched(SendTaskAssignedNotification::class);
});

test('assigning a non-member is rejected with an error', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $project = Project::factory()->create(['owner_id' => $owner->getKey()]);
    $project->members()->sync([$owner->getKey()]);
    $task = Task::factory()->create(['project_id' => $project->getKey()]);

    loginAsync($owner);

    $this->post(
        '/projects/' . $project->getKey() . '/tasks/' . $task->getKey() . '/assign',
        ['assignee' => $stranger->getKey()],
        ['HTTP_REFERER' => 'http://localhost:8000/projects/' . $project->getKey() . '/tasks/' . $task->getKey()]
    );

    expect($task->refresh()->assignee_id)->toBeNull();
});

// --- The job notifies the assignee ------------------------------------------

test('the job notifies the assignee: Mail::fake + Notifications::fake', function () {
    $mail = Mail::fake();
    $notifications = Notifications::fake();

    $assignee = User::factory()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assignee_id' => $assignee->getKey(),
        'title' => 'Ship it',
    ]);

    (new SendTaskAssignedNotification($task->getKey(), $assignee->getKey()))->handle();

    $notifications->assertSentTo($assignee, TaskAssigned::class);
});

test('the database channel writes a notifications row for the assignee', function () {
    // Real notifications dispatcher (no fake) so the DatabaseChannel + MailChannel
    // run; fake only the mailer so no SMTP is touched.
    Mail::fake();

    $assignee = User::factory()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assignee_id' => $assignee->getKey(),
        'title' => 'Write docs',
    ]);

    (new SendTaskAssignedNotification($task->getKey(), $assignee->getKey()))->handle();

    $row = asyncTable('notifications')
        ->where('notifiable_id', (string) $assignee->getKey())
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe(TaskAssigned::class)
        ->and($row->notifiable_type)->toBe(User::class);

    $data = json_decode((string) $row->data, true);
    expect($data['task_id'])->toBe($task->getKey())
        ->and($data['message'])->toContain('Write docs');
});

test('the task assigned notification mails the assignee (Mail::fake assertSent)', function () {
    $mail = Mail::fake();

    $assignee = User::factory()->create(['email' => 'assignee@example.com']);
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assignee_id' => $assignee->getKey(),
    ]);

    (new SendTaskAssignedNotification($task->getKey(), $assignee->getKey()))->handle();

    $mail->assertSent(\App\Mail\TaskAssignedMail::class);
});

// --- In-app notification list -----------------------------------------------

test('the in-app notification list shows the user database notifications', function () {
    $user = User::factory()->create();

    // Seed a database-channel notification row (the channel the in-app list
    // reads), mirroring DatabaseChannel's shape. Inserted directly so this
    // request-rendering test does not also render a mail Twig template in the
    // same process (the mail + web renders would share one Twig environment).
    asyncTable('notifications')->insert([
        'id' => (string) \Ions\Support\Str::uuid(),
        'notifiable_type' => User::class,
        'notifiable_id' => (string) $user->getKey(),
        'type' => TaskAssigned::class,
        'data' => json_encode(['type' => 'task_assigned', 'task_id' => 123, 'message' => 'You were assigned the task "Review PR".']),
        'read_at' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    loginAsync($user);

    $response = $this->get('/notifications');

    $response->assertOk()
        ->assertSee('Review PR');
});

// --- Failed jobs flow (in-process worker) -----------------------------------

test('the flaky job with tries=2 lands in failed_jobs exactly once', function () {
    dispatch((new FlakyDemoJob())->onConnection('database'));

    // Attempt 1 of 2: released back onto the queue, not yet failed.
    asyncWorkOnce(['--tries' => '2']);
    expect(asyncTable('jobs')->count())->toBe(1)
        ->and(asyncTable('failed_jobs')->count())->toBe(0);

    // Attempt 2 of 2: final failure → exactly one failed_jobs row.
    asyncWorkOnce(['--tries' => '2']);
    expect(asyncTable('jobs')->count())->toBe(0)
        ->and(asyncTable('failed_jobs')->count())->toBe(1);

    $row = asyncTable('failed_jobs')->first();
    expect($row->connection)->toBe('database')
        ->and($row->payload)->toContain('App\\\\Jobs\\\\FlakyDemoJob')
        ->and($row->exception)->toContain('FlakyDemoJob always fails');
});

test('queue:retry --all re-pushes the failed flaky job', function () {
    dispatch((new FlakyDemoJob())->onConnection('database'));
    // FlakyDemoJob declares $tries = 2 (wins over the CLI), so it fails into
    // failed_jobs only on the second worker iteration.
    asyncWorkOnce();
    asyncWorkOnce();
    expect(asyncTable('failed_jobs')->count())->toBe(1)
        ->and(asyncTable('jobs')->count())->toBe(0);

    $tester = asyncRunConsole(new \QueueRetryCommand(), ['--all' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(asyncTable('failed_jobs')->count())->toBe(0)
        ->and(asyncTable('jobs')->count())->toBe(1);
});

test('queue:failed lists the failed flaky job', function () {
    dispatch((new FlakyDemoJob())->onConnection('database'));
    asyncWorkOnce();
    asyncWorkOnce();

    $tester = asyncRunConsole(new \QueueFailedCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('FlakyDemoJob');
});

// --- Scheduler --------------------------------------------------------------

test('App\\Schedule registers the daily and weekly tasks', function () {
    $scheduler = ScheduleFacade::scheduler();
    $names = array_map(static fn ($t) => $t->getName(), $scheduler->tasks());

    expect($names)->toContain('prune-read-notifications')
        ->and($names)->toContain('weekly-digest');
});

test('schedule runs a due daily task at a frozen now', function () {
    $scheduler = ScheduleFacade::scheduler();

    // Midnight UTC: the daily 'prune-read-notifications' task is due.
    $now = new DateTimeImmutable('2026-06-10 00:00:00');
    $summary = $scheduler->runDue($now, static fn () => 0);

    expect($summary['failed'])->toBe(0)
        ->and($summary['ran'])->toBeGreaterThanOrEqual(1);
});

test('the prune task deletes notifications read over 30 days ago', function () {
    $user = User::factory()->create();

    asyncTable('notifications')->insert([
        'id' => (string) \Ions\Support\Str::uuid(),
        'notifiable_type' => User::class,
        'notifiable_id' => (string) $user->getKey(),
        'type' => TaskAssigned::class,
        'data' => json_encode(['message' => 'old']),
        'read_at' => (new DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
        'created_at' => (new DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
    ]);

    $deleted = Schedule::pruneReadNotifications();

    expect($deleted)->toBe(1)
        ->and(asyncTable('notifications')->count())->toBe(0);
});

test('the weekly digest queues a DigestMail per user (processed by the worker)', function () {
    $mail = Mail::fake();

    User::factory()->count(2)->create();

    // dispatchWeeklyDigest() queues one SendMailableJob per user onto the
    // 'database' connection. Two worker iterations process them; the faked
    // mailer records each materialized DigestMail.
    Schedule::dispatchWeeklyDigest();
    expect(asyncTable('jobs')->count())->toBe(2);

    asyncWorkOnce();
    asyncWorkOnce();

    $mail->assertSent(DigestMail::class);
    $mail->assertSentCount(2);
});

// --- Mailables build with the right content ---------------------------------

test('WelcomeMail builds with the recipient and greeting', function () {
    $email = (new WelcomeMail('newbie@example.com', 'Newbie'))->toSymfonyEmail();

    expect($email->getTo()[0]->getAddress())->toBe('newbie@example.com')
        ->and($email->getSubject())->toBe('Welcome to Taskflow')
        ->and($email->getHtmlBody())->toContain('Newbie');
});

test('DigestMail builds with the open-task count', function () {
    $email = (new DigestMail('active@example.com', 'Active', 4))->toSymfonyEmail();

    expect($email->getTo()[0]->getAddress())->toBe('active@example.com')
        ->and($email->getSubject())->toBe('Your weekly Taskflow digest')
        ->and($email->getHtmlBody())->toContain('4');
});

test('registering a user sends the WelcomeMail (Mail::fake)', function () {
    $mail = Mail::fake();
    Notifications::fake(); // EmailVerification routes through notifications

    $this->post('/register', [
        'name' => 'Registrant',
        'email' => 'registrant@example.com',
        'password' => 'sup3r-secret-pw',
        'password_confirmation' => 'sup3r-secret-pw',
    ]);

    $mail->assertSent(WelcomeMail::class);
});

// --- Demo-seed command ------------------------------------------------------

test('the taskflow:demo-seed command runs and seeds demo data', function () {
    $tester = asyncRunConsole(new \App\Commands\DemoSeedCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and(User::query()->where('email', 'alice@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'bob@example.com')->exists())->toBeTrue();
});
