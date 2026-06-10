<?php

declare(strict_types=1);

namespace Ions\Filesystem;

use Ions\Foundation\Kernel;
use Ions\Testing\Fakes\StorageFake;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * Thin static facade over the container-bound {@see FilesystemManager}.
 *
 * Every call delegates to the manager resolved from the application container
 * (bound by {@see \Ions\Providers\FilesystemProvider} under 'filesystem.manager').
 */
final class Storage
{
    /**
     * The container-bound filesystem driver manager.
     */
    public static function manager(): FilesystemManager
    {
        /** @var FilesystemManager $manager */
        $manager = Kernel::app()->get('filesystem.manager');

        return $manager;
    }

    /**
     * Resolve a named disk (defaults to config('filesystem.default')).
     */
    public static function disk(?string $name = null): Filesystem
    {
        return self::manager()->disk($name);
    }

    /**
     * Swap the named disk (default: config('filesystem.default')) for a
     * fresh in-memory disk and return a {@see StorageFake} handle with
     * assertion helpers.
     *
     * The memory adapter is forced regardless of the disk's configured
     * driver (local, s3, …), so files written through normal Storage calls
     * during a test never touch the real disk. The swap lives in the
     * container's FilesystemManager and is discarded with the container on
     * the next test boot.
     */
    public static function fake(?string $disk = null): StorageFake
    {
        $manager = self::manager();
        $name = $disk ?? $manager->getDefaultDriver();

        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $manager->set($name, $filesystem);

        return new StorageFake($name, $filesystem);
    }

    /**
     * Write contents to the default disk.
     *
     * @param array<string, mixed> $config
     */
    public static function put(string $path, string $contents, array $config = []): void
    {
        self::disk()->write($path, $contents, $config);
    }

    /**
     * Read a file from the default disk.
     */
    public static function get(string $path): string
    {
        return self::disk()->read($path);
    }

    /**
     * Determine whether a path exists on the default disk.
     */
    public static function exists(string $path): bool
    {
        return self::disk()->has($path);
    }

    /**
     * Delete a file from the default disk.
     */
    public static function delete(string $path): void
    {
        self::disk()->delete($path);
    }

    /**
     * Generate a public URL for a path on the default disk.
     */
    public static function url(string $path): string
    {
        return self::disk()->publicUrl($path);
    }
}
