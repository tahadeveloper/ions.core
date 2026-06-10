<?php

declare(strict_types=1);

namespace Ions\Console;

use Illuminate\Console\Command;
use Ions\Bundles\Path;
use Ions\Support\File;

/**
 * Base class for the install:* scaffolds shipped in src/commands that write
 * MULTIPLE files into the host root.
 *
 * The flow is refuse-all-or-nothing: every target is checked BEFORE anything
 * is written, and a conflict (existing file without --force) fails the run
 * listing each offender — a refused run never leaves a partial scaffold
 * behind. Subclasses supply the host-relative target => contents map and may
 * hook afterInstall() for follow-up hygiene (e.g. .gitignore entries).
 */
abstract class InstallerCommand extends Command
{
    /**
     * Map of host-relative target path => rendered file contents.
     *
     * @return array<string, string>
     */
    abstract protected function files(): array;

    public function handle(): int
    {
        $files = $this->files();

        $existing = [];
        foreach (array_keys($files) as $relative) {
            if (File::exists(Path::root($relative)) && !$this->option('force')) {
                $existing[] = $relative;
            }
        }

        if ($existing !== []) {
            $this->error('Refusing to overwrite existing files (re-run with --force to overwrite):');
            foreach ($existing as $relative) {
                $this->error('  - ' . $relative);
            }

            return self::FAILURE;
        }

        foreach ($files as $relative => $contents) {
            $target = Path::root($relative);
            $directory = dirname($target);

            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true, true);
            }

            File::put($target, $contents);
            $this->info('Created: ' . $relative);
        }

        $this->afterInstall();

        return self::SUCCESS;
    }

    /**
     * Hook running after every file has been written successfully.
     */
    protected function afterInstall(): void
    {
    }

    /**
     * Read a stub shipped under src/commands/stubs/assets/.
     */
    protected function stub(string $name): string
    {
        return File::get(dirname(__DIR__) . '/commands/stubs/assets/' . $name);
    }

    /**
     * Append the given entries to the host .gitignore (created when missing),
     * skipping lines already present — running twice never duplicates.
     *
     * @param list<string> $lines
     */
    protected function appendGitignore(array $lines): void
    {
        $path = Path::root('.gitignore');
        $current = File::exists($path) ? File::get($path) : '';

        $present = preg_split('/\R/', $current);
        $present = array_map('trim', $present === false ? [] : $present);
        $missing = array_values(array_diff($lines, $present));

        if ($missing === []) {
            $this->line('.gitignore already up to date.');

            return;
        }

        $prefix = ($current === '' || str_ends_with($current, "\n")) ? $current : $current . "\n";
        File::put($path, $prefix . implode("\n", $missing) . "\n");

        $this->info('Updated: .gitignore (+' . count($missing) . ' entries)');
    }
}
