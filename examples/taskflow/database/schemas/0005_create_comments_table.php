<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * comments — authored by a user on a task.
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('comments');
        $schema->create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('comments');
    }
};
