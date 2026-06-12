<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * notifications — the table the database notification channel inserts into.
 * Mirrors the framework stub at
 * src/Notifications/stubs/create_notifications_table.stub (used by the 13.5
 * in-app notifications).
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('notifications');
        $schema->create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('notifiable_type');
            $table->string('notifiable_id');
            $table->string('type');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('notifications');
    }
};
