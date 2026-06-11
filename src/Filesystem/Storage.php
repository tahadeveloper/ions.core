<?php

declare(strict_types=1);

namespace Ions\Filesystem;

use DateTimeImmutable;
use DateTimeInterface;
use Ions\Foundation\Kernel;
use Ions\Support\Str;
use Ions\Testing\Fakes\StorageFake;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Store a local file on the default disk under the given directory with a
     * random filename (the source file's extension is preserved). Accepts an
     * UploadedFile, any SplFileInfo, or a string path to a local file, and
     * returns the stored path.
     *
     * Note: this is a low-level convenience — it performs no upload
     * validation. User-supplied uploads should go through IonUpload /
     * UploadValidator first.
     */
    public static function putFile(string $path, SplFileInfo|string $file): string
    {
        if (is_string($file)) {
            $file = new SplFileInfo($file);
        }

        $extension = $file instanceof UploadedFile
            ? ($file->guessExtension() ?? pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))
            : $file->getExtension();

        $name = Str::random(40) . ($extension !== '' ? '.' . $extension : '');
        $target = ltrim(rtrim($path, '/') . '/' . $name, '/');

        $stream = @fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to read file [%s].', $file->getPathname()));
        }

        self::disk()->writeStream($target, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $target;
    }

    /**
     * Stream a file from the default disk as an attachment download.
     *
     * @param array<string, string> $headers Extra response headers.
     */
    public static function download(string $path, ?string $name = null, array $headers = []): StreamedResponse
    {
        $disk = self::disk();
        $filename = $name ?? basename($path);

        try {
            $mime = $disk->mimeType($path);
        } catch (FilesystemException) {
            $mime = 'application/octet-stream';
        }

        $response = new StreamedResponse(static function () use ($disk, $path): void {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);

        $fallback = str_replace(['%', '/', '\\'], '', Str::ascii($filename));
        $response->headers->set('Content-Type', $mime !== '' ? $mime : 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $fallback !== '' ? $fallback : 'download'
        ));

        return $response;
    }

    /**
     * List the file paths in a directory on the default disk (sorted).
     *
     * @return list<string>
     */
    public static function files(string $directory = '', bool $recursive = false): array
    {
        return self::listContents($directory, $recursive, static fn (StorageAttributes $item): bool => $item->isFile());
    }

    /**
     * List the directory paths in a directory on the default disk (sorted).
     *
     * @return list<string>
     */
    public static function directories(string $directory = '', bool $recursive = false): array
    {
        return self::listContents($directory, $recursive, static fn (StorageAttributes $item): bool => $item->isDir());
    }

    /**
     * Copy a file on the default disk (the source is kept).
     */
    public static function copy(string $from, string $to): void
    {
        self::disk()->copy($from, $to);
    }

    /**
     * Move a file on the default disk (the source is removed).
     */
    public static function move(string $from, string $to): void
    {
        self::disk()->move($from, $to);
    }

    /**
     * Generate a public URL for a path on the default disk.
     *
     * Uses the disk's URL generator — configured via the disk's 'public_url'
     * (or its Laravel-style alias 'url') config key, and built in for s3 —
     * falling back to config('app.app_url') + the path for disks without one.
     */
    public static function url(string $path): string
    {
        try {
            return self::disk()->publicUrl($path);
        } catch (UnableToGeneratePublicUrl) {
            return rtrim((string) config('app.app_url'), '/') . '/' . ltrim($path, '/');
        }
    }

    /**
     * Generate a temporary (signed, expiring) URL for a path on the default
     * disk. Supported by drivers whose adapter is a temporary URL generator —
     * s3 is; local/memory/ftp/sftp are not and throw a RuntimeException.
     *
     * @param DateTimeInterface|int $expiresAt Absolute expiry, or a TTL in seconds.
     */
    public static function temporaryUrl(string $path, DateTimeInterface|int $expiresAt): string
    {
        if (is_int($expiresAt)) {
            $expiresAt = new DateTimeImmutable('@' . (time() + $expiresAt));
        }

        try {
            return self::disk()->temporaryUrl($path, $expiresAt);
        } catch (UnableToGenerateTemporaryUrl $e) {
            throw new RuntimeException(sprintf(
                'The [%s] disk does not support temporary URLs (its driver has no temporary URL generator; s3 does).',
                self::manager()->getDefaultDriver()
            ), 0, $e);
        }
    }

    /**
     * Shared listing helper for files()/directories().
     *
     * @param callable(StorageAttributes): bool $filter
     * @return list<string>
     */
    private static function listContents(string $directory, bool $recursive, callable $filter): array
    {
        $paths = self::disk()
            ->listContents($directory, $recursive)
            ->filter($filter)
            ->map(static fn (StorageAttributes $item): string => $item->path())
            ->toArray();

        sort($paths);

        return $paths;
    }
}
