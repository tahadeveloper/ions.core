<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Ions\Support\DB;

/**
 * users.notifications_opt_out — a one-click-unsubscribe flag (13.6). The signed
 * unsubscribe link (ShareController::unsubscribe) flips it to true; a tampered
 * link is rejected by the 'signed' middleware before the flag is ever touched.
 */
return new class () extends Migration {
    public function up(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        if (!$schema->hasColumn('users', 'notifications_opt_out')) {
            $schema->table('users', function (Blueprint $table) {
                $table->boolean('notifications_opt_out')->default(false);
            });
        }
    }

    public function down(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        if ($schema->hasColumn('users', 'notifications_opt_out')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('notifications_opt_out');
            });
        }
    }
};
