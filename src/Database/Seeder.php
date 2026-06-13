<?php

declare(strict_types=1);

namespace Ions\Database;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Ions\Bundles\Path;

/**
 * Composable base for database seeders run by `db:seed`.
 *
 * Subclasses provide a `seed()` (preferred) or `run()` method — this class does
 * NOT declare either abstract so plain seeders stay compatible and the runner
 * can pick whichever the subclass defines. A `DatabaseSeeder` can orchestrate
 * others with `$this->call([UsersSeeder::class, ...])`.
 */
abstract class Seeder
{
    protected ?Command $command = null;

    public function setCommand(Command $command): static
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Run one or more child seeders. Each is instantiated, given this seeder's
     * console command (when it is itself a Seeder), and invoked via its
     * `seed()` method if present, otherwise `run()`.
     *
     * @param class-string|array<int, class-string> $seeders
     */
    public function call(string|array $seeders): void
    {
        foreach ((array) $seeders as $class) {
            $seeder = $this->makeSeeder($class);

            if ($seeder instanceof self && $this->command !== null) {
                $seeder->setCommand($this->command);
            }

            $this->command?->info("Seeding: {$class}");

            if (method_exists($seeder, 'seed')) {
                $seeder->seed();
            } elseif (method_exists($seeder, 'run')) {
                $seeder->run();
            } else {
                throw new InvalidArgumentException(
                    "Seeder [{$class}] has no seed() or run() method."
                );
            }
        }
    }

    /**
     * Instantiate a child seeder. When the class is not yet autoloadable (a
     * host that hasn't dumped its Database\Seeders autoload map), fall back to
     * requiring its file from database/seeders by short name — mirroring the
     * runner's file-loading robustness so composition works out of the box.
     *
     * @param class-string $class
     */
    private function makeSeeder(string $class): object
    {
        if (!class_exists($class)) {
            $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
            $file = Path::database('seeders/' . $short . '.php');

            if (is_file($file)) {
                require_once $file;
            }
        }

        return new $class();
    }
}
