<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * jobs + failed_jobs — the 'database' queue connection tables. Mirrors the
 * framework stub at src/Queue/stubs/create_jobs_table.stub (used by the 13.5
 * async work and config('queue.connections.database')).
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('jobs');
        $schema->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $schema->dropIfExists('failed_jobs');
        $schema->create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->dropIfExists('failed_jobs');
        $schema->dropIfExists('jobs');
    }
};
