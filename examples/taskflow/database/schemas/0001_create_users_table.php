<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * users — Taskflow accounts. Carries the framework's opt-in auth columns:
 * `email_verified_at` (VerifiesEmail) and the two_factor_* columns (the 13.3
 * TwoFactor flow), both nullable.
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->dropIfExists('users');
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('users');
    }
};
