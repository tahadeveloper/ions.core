<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * tasks — belong to a project, optionally assigned to a user. `status` is one
 * of todo|doing|done (App\Models\Task::STATUSES).
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('tasks');
        $schema->create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('todo')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('tasks');
    }
};
