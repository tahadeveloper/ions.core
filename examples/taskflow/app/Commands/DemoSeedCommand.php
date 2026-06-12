<?php

declare(strict_types=1);

namespace App\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * `taskflow:demo-seed` — (re)build a small demo dataset for exploring Taskflow
 * with `php bin/ions serve`. Runs every database/migrations migration (each is
 * idempotent: dropIfExists + create) so the tables exist, then runs the
 * DemoSeeder. All demo users sign in with the password "password".
 *
 * Auto-discovered from app/Commands by Ions\Console\Kernel — no config entry
 * needed.
 */
final class DemoSeedCommand extends Command
{
    protected $signature = 'taskflow:demo-seed';

    protected $description = 'Rebuild the Taskflow demo dataset (migrate + DemoSeeder)';

    public function handle(): int
    {
        $this->info('Migrating schema...');
        foreach (glob(Path::database('migrations') . '/*.php') ?: [] as $file) {
            $migration = require $file;
            $migration->up();
        }

        $this->info('Seeding demo data...');
        (new DemoSeeder())->seed();

        $this->info('Done. Demo users: alice@example.com / bob@example.com (password: "password").');

        return self::SUCCESS;
    }
}
