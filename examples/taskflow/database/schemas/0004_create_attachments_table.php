<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * attachments — files attached to a task.
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('attachments');
        $schema->create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('attachments');
    }
};
