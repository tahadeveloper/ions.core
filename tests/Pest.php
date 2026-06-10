<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function bootFixtureKernel(string $path = __DIR__ . '/fixtures/app'): void
{
    \Ions\Foundation\Kernel::resetForTesting();
    \Ions\Foundation\Kernel::boot($path);
}

/**
 * Run a single Illuminate console Command against the booted Ions container and
 * return the CommandTester so callers can assert exit code / output.
 *
 * @param array<string, mixed> $input
 */
function runConsoleCommand(\Illuminate\Console\Command $command, array $input = []): \Symfony\Component\Console\Tester\CommandTester
{
    $proxy = new \Ions\Console\ConsoleContainerProxy(\Ions\Foundation\Kernel::app());
    $app = new \Illuminate\Console\Application($proxy, new \Illuminate\Events\Dispatcher(), '1.0');
    $app->add($command);

    $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find($command->getName() ?? ''));
    $tester->execute($input);

    return $tester;
}

/**
 * Creates the notifications table on the in-memory SQLite connection,
 * mirroring src/Notifications/stubs/create_notifications_table.stub.
 * $table allows the config('notifications.table') override tests to
 * create their custom-named copy.
 */
function createNotificationsTable(string $table = 'notifications'): void
{
    $schema = \Ions\Foundation\Kernel::app()->get('db')->connection()->getSchemaBuilder();

    $schema->dropIfExists($table);

    $schema->create($table, function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('notifiable_type');
        $t->string('notifiable_id');
        $t->string('type');
        $t->text('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamp('created_at')->nullable();
        $t->index(['notifiable_type', 'notifiable_id']);
    });
}

/**
 * Creates all Sentinel tables on the in-memory SQLite connection.
 * Shared by SentinelUserProviderTest and GuardTest to avoid duplication.
 * InnoDB engine declarations in the original migration are ignored by SQLite.
 */
function createSentinelTables(): void
{
    $schema = \Ions\Foundation\Kernel::app()->get('db')->connection()->getSchemaBuilder();

    // Drop in reverse FK order to keep things clean across test runs.
    foreach (['throttle', 'role_users', 'roles', 'reminders', 'persistences', 'activations', 'users'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('users', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('email')->unique();
        $t->string('password');
        $t->text('permissions')->nullable();
        $t->timestamp('last_login')->nullable();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->timestamps();
    });

    $schema->create('activations', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->boolean('completed')->default(0);
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    $schema->create('persistences', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->timestamps();
        $t->unique('code');
    });

    $schema->create('reminders', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned();
        $t->string('code');
        $t->boolean('completed')->default(0);
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    $schema->create('roles', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('slug')->unique();
        $t->string('name');
        $t->text('permissions')->nullable();
        $t->timestamps();
    });

    $schema->create('role_users', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->integer('user_id')->unsigned();
        $t->integer('role_id')->unsigned();
        $t->nullableTimestamps();
        $t->primary(['user_id', 'role_id']);
    });

    $schema->create('throttle', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id')->unsigned()->nullable();
        $t->string('type');
        $t->string('ip')->nullable();
        $t->timestamps();
        $t->index('user_id');
    });
}
