<?php

declare(strict_types=1);

namespace Ions\Console;

use Illuminate\Console\Command;
use Ions\Support\File;

/**
 * Base class for the stub-driven make:* generators shipped in src/commands.
 *
 * Provides the shared generate() flow: read the {name} argument, validate it
 * as a bare PHP class name (rejecting paths, extensions and shell metacharacters
 * before they ever touch the filesystem), create the target directory, honour
 * the exists/--force guard, then stub read → placeholder replace → write.
 *
 * Subclasses stay registered via the composer classmap and only supply their
 * strings and overrides (stub path, target path, extra placeholders, hooks).
 */
abstract class GeneratorCommand extends Command
{
    /** A single valid PHP identifier (class name without namespace). */
    protected const CLASS_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** A valid class reference: identifier or backslash-separated FQCN. */
    protected const CLASS_REFERENCE_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** Human-readable label used in messages, e.g. "Resource". */
    abstract protected function type(): string;

    /** Absolute path of the stub file to render. */
    abstract protected function stubPath(): string;

    /** Absolute path of the file to generate for the validated class name. */
    abstract protected function targetPath(string $name): string;

    /**
     * Placeholder map applied to the stub contents.
     *
     * @return array<string, string>
     */
    protected function replacements(string $name): array
    {
        return ['{{ class }}' => $name];
    }

    /**
     * Hook running after name validation and before any filesystem work.
     * Return an exit code (self::FAILURE) to abort, or null to continue.
     */
    protected function prepare(string $name): ?int
    {
        return null;
    }

    public function handle(): int
    {
        return $this->generate();
    }

    /**
     * The shared generation flow used by every make:* generator.
     */
    protected function generate(): int
    {
        $name = $this->argument('name');
        $name = is_string($name) ? $name : '';

        if (!$this->isValidClassName($name)) {
            $this->error('Invalid class name: ' . $name . ' (expected a bare class name like FooBar)');

            return self::FAILURE;
        }

        $abort = $this->prepare($name);
        if ($abort !== null) {
            return $abort;
        }

        $target = $this->targetPath($name);
        $directory = dirname($target);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        if (File::exists($target) && !$this->option('force')) {
            $this->error($this->type() . ' already exists: ' . $name . ' (use --force to overwrite)');

            return self::FAILURE;
        }

        $stub = $this->stubPath();

        if (!File::exists($stub)) {
            $this->error('Stub not found: ' . $stub);

            return self::FAILURE;
        }

        $replacements = $this->replacements($name);

        File::put($target, str_replace(
            array_keys($replacements),
            array_values($replacements),
            File::get($stub)
        ));

        $this->info($this->type() . ' created successfully: ' . $name);

        return self::SUCCESS;
    }

    /** Whether the value is a single valid PHP class name (no namespace). */
    protected function isValidClassName(string $name): bool
    {
        return preg_match(self::CLASS_NAME_PATTERN, $name) === 1;
    }

    /** Whether the value is a valid class reference (short name or FQCN). */
    protected function isValidClassReference(string $name): bool
    {
        return preg_match(self::CLASS_REFERENCE_PATTERN, $name) === 1;
    }
}
