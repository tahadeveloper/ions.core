<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\QueueManager;
use Ions\Foundation\Kernel;
use IonsFixture\RecordingJob;

beforeEach(fn () => bootFixtureKernel());

/** Create the database queue tables on the in-memory sqlite connection. */
function createQueueTables(): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('jobs');
    $schema->dropIfExists('failed_jobs');

    $schema->create('jobs', function (Blueprint $t) {
        $t->bigIncrements('id');
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });

    $schema->create('failed_jobs', function (Blueprint $t) {
        $t->id();
        $t->string('uuid')->unique();
        $t->text('connection');
        $t->text('queue');
        $t->longText('payload');
        $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });
}

test('the queue manager is bound in the container', function () {
    expect(Kernel::app()->has('queue'))->toBeTrue()
        ->and(Kernel::app()->get('queue'))->toBeInstanceOf(QueueManager::class);
});

test('a job dispatched on the sync connection runs immediately', function () {
    RecordingJob::$ran = [];

    dispatch(new RecordingJob('sync-payload'));

    expect(RecordingJob::$ran)->toBe(['sync-payload']);
});

test('a job pushed to the database connection is stored, not run', function () {
    createQueueTables();
    RecordingJob::$ran = [];

    dispatch((new RecordingJob('db-payload'))->onConnection('database'));

    // Not executed synchronously...
    expect(RecordingJob::$ran)->toBe([]);
    // ...but persisted to the jobs table.
    expect(Kernel::app()->get('db')->connection()->table('jobs')->count())->toBe(1);
});

test('queue:work --once processes one stored database job and consumes the row', function () {
    createQueueTables();
    RecordingJob::$ran = [];

    dispatch((new RecordingJob('worked'))->onConnection('database'));
    expect(Kernel::app()->get('db')->connection()->table('jobs')->count())->toBe(1);

    $command = new QueueWorkCommand();
    $tester = runConsoleCommand($command, ['connection' => 'database', '--once' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(RecordingJob::$ran)->toBe(['worked'])
        ->and(Kernel::app()->get('db')->connection()->table('jobs')->count())->toBe(0);
});
