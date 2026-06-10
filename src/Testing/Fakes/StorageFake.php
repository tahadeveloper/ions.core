<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use League\Flysystem\Filesystem;
use PHPUnit\Framework\Assert;

/**
 * Handle on a faked storage disk, returned by
 * {@see \Ions\Filesystem\Storage::fake()}.
 *
 * Wraps the fresh in-memory Flysystem disk that replaced the named disk in
 * the container's FilesystemManager, plus assertion helpers over its
 * contents. Files written through normal Storage calls during the test land
 * here and evaporate with the container.
 */
final class StorageFake
{
    public function __construct(
        private readonly string $name,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * The name of the disk this fake replaced.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The underlying in-memory Flysystem disk (the same instance
     * Storage::disk($name) now resolves).
     */
    public function disk(): Filesystem
    {
        return $this->filesystem;
    }

    /**
     * Assert a file exists at the given path on the fake disk.
     */
    public function assertStored(string $path): self
    {
        Assert::assertTrue(
            $this->filesystem->has($path),
            sprintf('Expected [%s] to exist on the fake [%s] disk, but it does not.', $path, $this->name)
        );

        return $this;
    }

    /**
     * Alias of {@see assertStored()}.
     */
    public function assertExists(string $path): self
    {
        return $this->assertStored($path);
    }

    /**
     * Assert no file exists at the given path on the fake disk.
     */
    public function assertMissing(string $path): self
    {
        Assert::assertFalse(
            $this->filesystem->has($path),
            sprintf('Expected [%s] to be missing on the fake [%s] disk, but it exists.', $path, $this->name)
        );

        return $this;
    }
}
