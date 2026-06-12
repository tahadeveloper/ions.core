<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * projects — owned by a user. `note` will hold an at-rest-encrypted value
 * (13.6); `share_token` backs the public/shared board link (13.6). Both
 * nullable here.
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('projects');
        $schema->create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('note')->nullable();
            $table->string('share_token')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('projects');
    }
};
