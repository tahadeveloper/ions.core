<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Exception;
use InvalidArgumentException;
use Ions\Filesystem\FilesystemManager;
use Ions\Security\UploadValidator;
use Ions\Support\File;
use Ions\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Legacy static disk API, kept for backwards compatibility.
 *
 * Disk acquisition is routed through the shared {@see FilesystemManager}
 * (container binding 'filesystem.manager'), so disks swapped via
 * Ions\Filesystem\Storage::fake() intercept IonDisk reads/writes in tests.
 *
 * New code should prefer {@see \Ions\Filesystem\Storage} — IonDisk is a
 * removal candidate for 5.0.
 */
class IonDisk
{
    private static string $type;
    private static $basePath;
    private static $bucket;

    /**
     * Ad-hoc Flysystem disks built from IonDisk's own config view (legacy s3
     * shape with runtime-mutable bucket/basePath), cached per shape.
     *
     * @var array<string, Filesystem>
     */
    private static array $builtDisks = [];

    public static function init(): void
    {
        // Initialize properties
        self::$type = self::getTypeFromEnv();
        self::$basePath = config('filesystem.disks.local.root');
        self::$bucket = config('filesystem.disks.s3.bucket');
        self::$builtDisks = [];
    }

    private static function getTypeFromEnv()
    {
        return config('filesystem.disks.default', 'local');
    }

    /**
     * Resolve the Flysystem disk for the current $type.
     *
     * Resolution order:
     *  1. a disk swapped into the shared manager (Storage::fake()) always wins;
     *  2. 'local' resolves as the manager's named 'local' disk when the host
     *     config declares a driver for it (same root config key as the legacy
     *     build, now shared/memoized with Storage::disk('local'));
     *  3. anything else (s3 with runtime-mutable bucket/basePath, driverless
     *     legacy config shapes) is built from IonDisk's own config view.
     */
    private static function filesystem(): Filesystem
    {
        $manager = self::manager();

        if ($manager->isOverridden(self::$type)) {
            return $manager->disk(self::$type);
        }

        if (self::$type === 'local' && is_string(config('filesystem.disks.local.driver'))) {
            return $manager->disk('local');
        }

        $key = self::$type . '|' . (string) self::$bucket . '|' . (string) self::$basePath;

        return self::$builtDisks[$key] ??= $manager->buildDisk(self::diskConfig());
    }

    /**
     * The container-bound FilesystemManager (falls back to a fresh instance
     * when the kernel/container is not available, e.g. legacy direct usage).
     */
    private static function manager(): FilesystemManager
    {
        try {
            /** @var FilesystemManager $manager */
            $manager = \Ions\Foundation\Kernel::app()->get('filesystem.manager');
            return $manager;
        } catch (\Throwable) {
            return new FilesystemManager();
        }
    }

    /**
     * Resolve the driver config for IonDisk's current $type, layering in its
     * runtime-mutable bucket/basePath for the s3 driver.
     *
     * @return array<string, mixed>
     */
    private static function diskConfig(): array
    {
        if (self::$type === 'local') {
            return [
                'driver' => 'local',
                'root' => config('filesystem.disks.local.root'),
            ];
        }

        if (self::$type === 's3') {
            return [
                'driver' => 's3',
                'region' => config('filesystem.disks.s3.region'),
                'version' => config('filesystem.disks.s3.version', 'latest'),
                'key' => config('filesystem.disks.s3.key'),
                'secret' => config('filesystem.disks.s3.secret'),
                'bucket' => self::$bucket,
                'root' => self::$basePath,
            ];
        }

        throw new RuntimeException("Unsupported IonDisk type: " . self::$type);
    }

    public static function putFile($fileContent, $originalFilename, $userProvidedPath, $withOriginal = false, array $options = []): array
    {
        // Security: validate extension against allow-list BEFORE writing to disk
        $allowed = $options['allowed'] ?? config('app.uploads.allowed', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip']);
        $validator = new UploadValidator($allowed, (array) config('app.uploads.mime_map', []));
        if (!$validator->isAllowed($originalFilename)) {
            return ['error' => 'File extension not allowed'];
        }

        // Magic-bytes gate on the raw content (no file path exists yet here):
        // the buffer's finfo MIME must agree with the claimed extension.
        if (!$validator->isContentValidBuffer((string) $fileContent, (string) $originalFilename)) {
            return ['error' => 'File content does not match its extension'];
        }

        // Derive safe extension and sanitized stem from validated filename
        $extension = $validator->safeExtension($originalFilename);
        $randomName = $withOriginal
            ? Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME))
            : Str::random(15);
        $randomName .= '.' . $extension;

        // FINDING 1: $userProvidedPath is a Flysystem KEY (relative to the disk
        // root); reject any `..`/absolute escape before it is joined and
        // written. Flysystem roots the actual write at the disk root.
        if (!self::isWriteKeyContained((string) $userProvidedPath)) {
            return ['error' => 'Refusing to write outside the upload root'];
        }

        $filePath = "$userProvidedPath/$randomName";

        // Upload the file to the specified path
        try {
            self::filesystem()->write($filePath, $fileContent);
            return [
                'upload_name' => $randomName,
                'original_name' => $originalFilename,
                'file_size' => strlen($fileContent),
            ];
        } catch (Exception $e) {
            // Handle any errors (e.g., disk full, permissions issue)
            return ['error' => $e->getMessage()];
        }
    }

    public static function getFile($filePath): array|string
    {
        // Retrieve file content or secure URL
        try {
            return self::filesystem()->read($filePath);
        } catch (Exception $e) {
            // Handle any errors (e.g., file not found)
            return ['error' => $e->getMessage()];
        }
    }

    public static function download($filePath, $downloadPath): array|string
    {
        // FINDING 3: the destination path is caller-controlled and was opened
        // with a raw fopen('wb'). Constrain it to an allowed root (the local
        // disk root or the system temp dir) so a traversal payload cannot drop
        // a file anywhere on the filesystem.
        if (!self::isDownloadDestAllowed((string) $downloadPath)) {
            return ['error' => 'Refusing to write outside the allowed download root'];
        }

        // Read the stored file from the configured disk and write it to the local destination path.
        try {
            $filesystem = self::filesystem();
            if ($filesystem->has($filePath)) {
                $stream = $filesystem->readStream($filePath);
                $dest = fopen($downloadPath, 'wb');
                if ($dest === false) {
                    fclose($stream);
                    return ['error' => 'Could not open the download destination'];
                }
                stream_copy_to_stream($stream, $dest);
                fclose($stream);
                fclose($dest);
                // Downloaded file is now available at $downloadPath
                return ['success' => true];
            } else {
                // Handle the case where the file doesn't exist on the disk
                return ['error' => 'File not found'];
            }
        } catch (Exception $e) {
            // Handle any errors (e.g., read failure, permission issue)
            return ['error' => $e->getMessage()];
        }
    }


    public static function deleteFile($filePath): array
    {
        // Delete a file
        try {
            self::filesystem()->delete($filePath);
            return ['success' => true];
        } catch (Exception $e) {
            // Handle any errors (e.g., file not found)
            return ['error' => $e->getMessage()];
        }
    }

    public static function disk($type): self
    {
        // type get from config/filesystem.php
        $defType = config('filesystem.disks.default', 'local');
        if (!empty($type)) {
            self::$type = $type;
        } else {
            self::$type = $defType;
        }
        // Drop ad-hoc built disks so the next operation re-resolves against
        // the (possibly re-booted) config. Named disks are memoized by the
        // shared FilesystemManager, which is per-container already.
        self::$builtDisks = [];
        return new self();
    }

    public static function getType(): string
    {
        return self::$type;
    }

    /**
     * @return S3Client
     */
    public static function getS3Client(): S3Client
    {
        $options = [
            'region' => config('filesystem.disks.s3.region'),
            'version' => config('filesystem.disks.s3.version', 'latest'),
            'credentials' => [
                'key' => config('filesystem.disks.s3.key'),
                'secret' => config('filesystem.disks.s3.secret'),
            ],
        ];
        // Create the S3 client
        return new S3Client($options);
    }

    public static function flySystem(): ?Filesystem
    {
        // A disk swapped into the shared manager (Storage::fake()) routes
        // every operation — including the local branches that normally use
        // native PHP file calls — through the faked Flysystem disk, so tests
        // intercept IonDisk writes and the real disk stays untouched.
        if (self::manager()->isOverridden(self::$type)) {
            return self::manager()->disk(self::$type);
        }

        switch (self::$type) {
            case 'local':
                // Local operations are handled with native PHP/Filesystem calls
                // by the callers; returning null preserves that BC branch.
                return null;
            case 's3':
                return self::filesystem();
            default:
                throw new InvalidArgumentException("Unsupported disk type: " . self::$type);
        }
    }

    public static function put(mixed $file, string $path = '', bool $withOriginal = false, array $options = []): array
    {
        $disk = self::flySystem();

        // check if file is valid
        if (!($file instanceof UploadedFile)) {
            throw new InvalidArgumentException('Invalid file, must be an instance of UploadedFile');
        }

        $fileNameWithExt = $file->getClientOriginalName();

        // Security: validate extension against allow-list BEFORE any write (covers both local and cloud paths)
        $allowed = $options['allowed'] ?? config('app.uploads.allowed', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip']);
        $validator = new UploadValidator($allowed, (array) config('app.uploads.mime_map', []));
        if (!$validator->isAllowed($fileNameWithExt)) {
            return ['error' => true, 'message' => 'File extension not allowed'];
        }

        // Magic-bytes gate: the uploaded temp file's content must agree with
        // the claimed extension before any local move or cloud write.
        $realPath = $file->getRealPath();
        if ($realPath === false || !$validator->isContentValid($realPath, $fileNameWithExt)) {
            return ['error' => true, 'message' => 'File content does not match its extension'];
        }

        // Derive the stored extension from the validator so allow-list check and on-disk extension are the same value
        $extension = $validator->safeExtension($fileNameWithExt);

        // Sanitize the stem when keeping original name to prevent client-controlled characters on disk
        $randomFilename = $withOriginal
            ? Str::slug(pathinfo($fileNameWithExt, PATHINFO_FILENAME))
            : Str::random(15);

        if ($disk === null) {
            return self::handleLocalUpload($file, $path, $fileNameWithExt, $randomFilename, $extension, $options);
        }

        return self::handleCloudUpload($file, $disk, $path, $fileNameWithExt, $randomFilename, $extension);
    }

    private static function handleLocalUpload(UploadedFile $file, string $path, string $fileNameWithExt, string $randomFilename, string $extension, array $options): array
    {
        // FINDING 1: the caller-controlled target directory must not traverse
        // out via `..` (move() would otherwise create an escaped directory).
        if (!self::isWriteTargetContained($path)) {
            return ['error' => true, 'message' => 'Refusing to write outside the upload root'];
        }

        $storeName = $randomFilename . '.' . $extension;
        $size = $file->getSize(); // capture before move() invalidates the temp path
        try {
            $file->move($path, $storeName);
        } catch (FileException $e) {
            throw new RuntimeException('Upload failed: ' . $e->getMessage());
        }

        return [
            'error' => false,
            'originalName' => $fileNameWithExt,
            'filename' => $storeName,
            'size' => $size,
        ];
    }

    private static function handleCloudUpload(UploadedFile $file, Filesystem $disk, string $path, string $fileNameWithExt, string $randomFilename, string $extension): array
    {
        // No explicit containment here on purpose: a Flysystem key is relative
        // within the disk root (callers may pass an absolute-looking prefix that
        // the adapter treats as a key), and Flysystem's path normalizer already
        // throws PathTraversalDetected on any `..`. A real-FS escape is not
        // possible through the managed disk. See docs/security-audit-bundles.md.
        $stream = fopen($file->getRealPath(), 'rb+');
        $disk->writeStream($path . '/' . $randomFilename . '.' . $extension, $stream);
        fclose($stream);

        // Return information about uploaded file
        return [
            'error' => false,
            'originalName' => $fileNameWithExt,
            'filename' => $randomFilename . '.' . $extension,
            'size' => $file->getSize(),
        ];
    }

    /**
     * Containment for a Flysystem write KEY (relative to the disk root).
     *
     * Used by putFile(), whose $userProvidedPath is a relative key joined onto
     * a random name and written through Flysystem (rooted at the disk root).
     * Reject empty/null-byte values, any absolute prefix, and any `..` segment
     * — Flysystem v3 also normalizes/rejects traversal, this is defense in
     * depth at the boundary. Legitimate nested keys (`avatars/2024`) pass.
     */
    private static function isWriteKeyContained(string $key): bool
    {
        if ($key === '' || str_contains($key, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $key);
        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:/#', $normalized) === 1) {
            return false; // absolute escape
        }

        return !in_array('..', explode('/', $normalized), true);
    }

    /**
     * Write-side path-traversal guard (FINDING 1/3).
     *
     * The upload/download TARGET directory is request-controllable and may not
     * exist yet, so we cannot canonicalize the leaf. Instead we reject any `..`
     * traversal segment or null byte outright, then — honoring FINDING 8 —
     * canonicalize the PARENT directory: when the parent realpath()s we require
     * it to stay inside the configured local disk root (when one is set). A
     * non-existent parent (realpath === false) only passes the cheap segment
     * check, so a `..`-bearing path can never bypass containment via a missing
     * target. Returns true when the target is safe to write.
     */
    private static function isWriteTargetContained(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if (in_array('..', explode('/', $normalized), true)) {
            return false; // any traversal segment is rejected, fail-closed
        }

        // When a local disk root is configured, also assert the canonical
        // parent stays inside it (best-effort: only when the parent exists).
        $root = (string) config('filesystem.disks.local.root', '');
        if ($root === '') {
            return true;
        }
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return true; // no canonical root to compare against
        }

        $parent = \dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            // Parent does not exist yet. With no `..` segment present (checked
            // above) it cannot traverse out; allow the write to create it.
            return true;
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $realParent = rtrim($realParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($realParent, $realRoot);
    }

    /**
     * Containment for the download() destination (FINDING 3).
     *
     * The destination is a real local filesystem path supplied by the caller
     * and opened with a raw fopen('wb'). It is legitimately an absolute path
     * (the temp/local-disk area), so we cannot forbid absolutes; instead we
     * reject any `..` traversal segment or null byte (the actual escape vector)
     * and — when an existing parent canonicalizes under the configured local
     * disk root or the system temp dir — accept. A parent that exists but lands
     * OUTSIDE both allowed roots is rejected (honoring FINDING 8: no traversal
     * may bypass containment). A not-yet-existing parent with no `..` segment is
     * allowed (the leaf write may create the file in an allowed tree).
     */
    private static function isDownloadDestAllowed(string $downloadPath): bool
    {
        if ($downloadPath === '' || str_contains($downloadPath, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $downloadPath);
        if (in_array('..', explode('/', $normalized), true)) {
            return false; // traversal segment — fail-closed
        }

        $allowedRoots = [];
        $localRoot = (string) config('filesystem.disks.local.root', '');
        if ($localRoot !== '' && ($r = realpath($localRoot)) !== false) {
            $allowedRoots[] = rtrim($r, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        foreach (['/tmp', sys_get_temp_dir()] as $tmpDir) {
            if (($tmp = realpath($tmpDir)) !== false) {
                $allowedRoots[] = rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }

        $realParent = realpath(\dirname($downloadPath));
        if ($realParent === false) {
            // Parent not created yet: with no `..` segment it cannot traverse
            // out, so allow the write to materialise it.
            return true;
        }
        $realParent = rtrim($realParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($allowedRoots as $root) {
            if (str_starts_with($realParent, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Path-traversal guard for the native (local) delete branches.
     *
     * IonDisk::delete()/deleteDirectory() receive an ABSOLUTE filesystem path
     * (callers build it via Path::files()/filesRoot()) and feed it straight to
     * unlink()/rmdir(). Reject any path that contains a `..` segment or whose
     * canonical real path escapes the configured local disk root, so a caller
     * cannot be coerced into deleting `.env` or any file outside uploads.
     */
    private static function assertLocalPathContained(string $path): void
    {
        $root = (string) config('filesystem.disks.local.root', '');
        if ($root === '') {
            // No configured local root → derive the uploads root so the
            // containment check still applies (instead of `..`-rejection only).
            $root = (string) Path::filesRoot('');
        }

        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true)) {
            throw new RuntimeException("Refusing to delete a path containing '..': $path");
        }

        if ($root === '') {
            return;
        }

        $realRoot = realpath($root);
        $realTarget = realpath($path);
        // A missing target is handled by the caller's existence check; only
        // enforce containment when both ends canonicalize.
        if ($realRoot === false || $realTarget === false) {
            return;
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realTarget . DIRECTORY_SEPARATOR, $realRoot)) {
            throw new RuntimeException("Refusing to delete a path outside the disk root: $path");
        }
    }

    public static function delete(string $path): void
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            self::assertLocalPathContained($path);
            if (file_exists($path)) {
                unlink($path);
            } else {
                throw new RuntimeException("File does not exist: $path");
            }
        } else {
            // Cloud storage handling (using Flysystem)
            if ($disk->has($path)) {
                $disk->delete($path);
            }
        }
    }

    public static function get($path): bool|string
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Failed to read file.');
            }
            return $content;
        }

        // Cloud storage handling (using Flysystem)
        try {
            $stream = $disk->readStream($path);
            if (!$stream) {
                throw new RuntimeException('File not found.');
            }
            $content = stream_get_contents($stream);
            fclose($stream);
            return $content;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve file: ' . $e->getMessage());
        }
    }

    public static function getObject($path, $downloadTemp)
    {
        $client = self::getS3Client();
        try {
            $result = $client->getObject([
                'Bucket' => self::$bucket,
                'Key' => $path,
                'SaveAs' => $downloadTemp,
            ]);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Build a time-limited, *presigned* S3 URL for $path (FINDING 7).
     *
     * Previously this returned an UNSIGNED, permanent public object URL (the
     * presign call was commented out) — an authorization bug, since callers
     * expect a credential that expires. It now returns a real presigned URL via
     * the AWS SDK's createPresignedRequest().
     *
     * @param int|string $expirationTime Seconds (int) or a strtotime-relative
     *        string (e.g. '+20 minutes') accepted by the SDK; defaults to 1h.
     *
     * Residual (host policy): the bucket is NOT allow-listed here. Hosts must
     * never pass attacker-controlled input as the bucket override.
     */
    public static function getSignedUrl($path, $expirationTime = 3600, $defaultOptions = null): string // 1 hour
    {
        // Apply a per-call override and restore it afterwards (no cross-request
        // bleed in worker mode — see applyOptionOverrides()).
        $restore = self::applyOptionOverrides($defaultOptions);

        try {
            $disk = self::flySystem();

            if ($disk === null) {
                throw new RuntimeException('Signed URLs are not available for local storage.');
            }

            $s3Client = self::getS3Client();
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => self::$bucket,
                'Key' => $path,
            ]);

            // Normalize an int seconds value to an SDK-accepted expiry.
            $expiry = is_int($expirationTime) ? "+{$expirationTime} seconds" : $expirationTime;
            $request = $s3Client->createPresignedRequest($command, $expiry);

            return (string) $request->getUri();
        } finally {
            $restore();
        }
    }

    /**
     * Apply a per-call bucket/basePath override and return a restore closure.
     *
     * FINDING 7: callers mutate static self::$bucket/self::$basePath from
     * per-call options; without resetting them the override bled across
     * requests in worker mode. Each mutating method now applies the override
     * and restores the previous static state via the returned closure in a
     * finally block (the per-call override feature is preserved).
     *
     * @return callable():void
     */
    private static function applyOptionOverrides($defaultOptions): callable
    {
        $prevBucket = self::$bucket;
        $prevBasePath = self::$basePath;

        if ($defaultOptions) {
            if ($defaultOptions->has('bucket')) {
                self::$bucket = $defaultOptions->get('bucket');
            }
            if ($defaultOptions->has('basePath')) {
                self::$basePath = $defaultOptions->get('basePath');
            }
        }

        return static function () use ($prevBucket, $prevBasePath): void {
            self::$bucket = $prevBucket;
            self::$basePath = $prevBasePath;
        };
    }

    public static function getUrl(string $path, $defaultOptions = null): string
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            $s3Client = self::getS3Client();

            if ($defaultOptions && $defaultOptions->has('cdn_base_url')) {
                return $defaultOptions->get('cdn_base_url') . '/' . $path;
            }

            try {
                return $s3Client->getObjectUrl(self::$bucket, $path);
            } catch (AwsException $e) {
                throw new RuntimeException('Failed to get URL: ' . $e->getMessage());
            }
        } finally {
            $restore();
        }
    }

    public static function exists($path, $defaultOptions = null): bool
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            $disk = self::flySystem();

            if ($disk === null) {
                if ($defaultOptions && $defaultOptions->has('target')) {
                    $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
                } else {
                    $path = Path::files($path);
                }
                return File::exists($path);
            }

            return $disk->has($path);
        } finally {
            $restore();
        }
    }

    public static function size($path, $defaultOptions = null): int
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            $disk = self::flySystem();

            if ($disk === null) {
                if ($defaultOptions && $defaultOptions->has('target')) {
                    $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
                } else {
                    $path = Path::files($path);
                }
                return File::size($path);
            }

            return $disk->fileSize($path);
        } finally {
            $restore();
        }
    }

    public static function mimeType($path, $defaultOptions = null): string
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            $disk = self::flySystem();

            if ($disk === null) {
                if ($defaultOptions && $defaultOptions->has('target')) {
                    $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
                } else {
                    $path = Path::files($path);
                }
                return File::mimeType($path);
            }

            return $disk->mimeType($path);
        } finally {
            $restore();
        }
    }

    public static function createDirectory($path, $defaultOptions = null): bool
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            $disk = self::flySystem();

            if ($disk === null) {
                if ($defaultOptions && $defaultOptions->has('target')) {
                    $path = Path::filesRoot($defaultOptions->get('target') . '/' . $path);
                } else {
                    $path = Path::files($path);
                }
                return File::makeDirectory($path, 0777, true);
            }

            try {
                $disk->createDirectory($path);
            } catch (FilesystemException $e) {
                throw new RuntimeException('Failed to create directory: ' . $e->getMessage());
            }
            return true;
        } finally {
            $restore();
        }
    }

    public static function deleteDirectory(string $path): void
    {
        $disk = self::flySystem();

        if ($disk === null) {
            // Local storage handling
            self::assertLocalPathContained($path);
            if (is_dir($path)) {
                $files = array_diff(scandir($path), ['.', '..']);
                foreach ($files as $file) {
                    $filePath = $path . DIRECTORY_SEPARATOR . $file;
                    if (is_dir($filePath)) {
                        self::deleteDirectory($filePath);
                    } else {
                        unlink($filePath);
                    }
                }
                rmdir($path);
            } else {
                throw new RuntimeException("Directory does not exist: $path");
            }
        } else {
            // Cloud storage handling (using Flysystem)
            if ($disk->has($path)) {
                $disk->deleteDirectory($path);
            }
        }
    }

    public static function copy($sourcePath, $destinationPath, $defaultOptions = null): bool
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            if ($defaultOptions && $defaultOptions->has('removePath')) {
                self::$basePath = '';
                if ($defaultOptions->has('fromPath') && $defaultOptions->has('toPath')) {
                    $sourcePath = $defaultOptions->get('fromPath') . '/' . $sourcePath;
                    $destinationPath = $defaultOptions->get('toPath') . '/' . $destinationPath;
                }
            }

            $disk = self::flySystem();

            if ($disk === null) {
                // remove fromPath/toPath prefixes when present (null-safe, mirrors
                // move()); both ends then route through Path::files()/filesRoot(),
                // which enforce traversal containment (FINDING 2/6).
                if ($defaultOptions && $defaultOptions->has('fromPath')) {
                    $sourcePath = str_replace($defaultOptions->get('fromPath') . '/', '', $sourcePath);
                }
                if ($defaultOptions && $defaultOptions->has('toPath')) {
                    $destinationPath = str_replace($defaultOptions->get('toPath') . '/', '', $destinationPath);
                }

                if ($defaultOptions && $defaultOptions->has('targetFrom')) {
                    $sourcePath = Path::filesRoot($defaultOptions->get('targetFrom') . '/' . $sourcePath);
                } else {
                    $sourcePath = Path::files($sourcePath);
                }
                if ($defaultOptions && $defaultOptions->has('targetTo')) {
                    $destinationPath = Path::filesRoot($defaultOptions->get('targetTo') . '/' . $destinationPath);
                } else {
                    $destinationPath = Path::files($destinationPath);
                }
                return File::copy($sourcePath, $destinationPath);
            }

            try {
                if ($defaultOptions && $defaultOptions->get('bucket') && $defaultOptions->has('otherBucket')) {
                    $client = self::getS3Client();
                    $client->copyObject([
                        'Bucket' => $defaultOptions->get('otherBucket'),
                        'CopySource' => $defaultOptions->get('bucket') . '/' . $sourcePath,
                        'Key' => $destinationPath,
                    ]);
                } else {
                    $disk->copy($sourcePath, $destinationPath);
                }
            } catch (FilesystemException $e) {
                throw new RuntimeException('Failed to copy file: ' . $e->getMessage());
            }
            return true;
        } finally {
            $restore();
        }
    }

    public static function move($sourcePath, $destinationPath, $defaultOptions = null): bool
    {
        $restore = self::applyOptionOverrides($defaultOptions);
        try {
            if ($defaultOptions && $defaultOptions->has('removePath')) {
                self::$basePath = '';
                if ($defaultOptions->has('fromPath') && $defaultOptions->has('toPath')) {
                    $sourcePath = $defaultOptions->get('fromPath') . '/' . $sourcePath;
                    $destinationPath = $defaultOptions->get('toPath') . '/' . $destinationPath;
                }
            }

            $disk = self::flySystem();

            if ($disk === null) {
                // remove fromPath from sourcePath
                if ($defaultOptions && $defaultOptions->has('fromPath')) {
                    $sourcePath = str_replace($defaultOptions->get('fromPath') . '/', '', $sourcePath);
                }
                if ($defaultOptions && $defaultOptions->has('toPath')) {
                    $destinationPath = str_replace($defaultOptions->get('toPath') . '/', '', $destinationPath);
                }

                if ($defaultOptions && $defaultOptions->has('targetFrom')) {
                    $sourcePath = Path::filesRoot($defaultOptions->get('targetFrom') . '/' . $sourcePath);
                } else {
                    $sourcePath = Path::files($sourcePath);
                }
                if ($defaultOptions && $defaultOptions->has('targetTo')) {
                    $destinationPath = Path::filesRoot($defaultOptions->get('targetTo') . '/' . $destinationPath);
                } else {
                    $destinationPath = Path::files($destinationPath);
                }
                return File::move($sourcePath, $destinationPath);
            }

            try {
                if ($defaultOptions && $defaultOptions->get('bucket') && $defaultOptions->has('otherBucket')) {
                    $client = self::getS3Client();
                    $client->copyObject([
                        'Bucket' => $defaultOptions->get('otherBucket'),
                        'CopySource' => $defaultOptions->get('bucket') . '/' . $sourcePath,
                        'Key' => $destinationPath,
                    ]);
                } else {
                    $disk->move($sourcePath, $destinationPath);
                }
            } catch (FilesystemException $e) {
                throw new RuntimeException('Failed to move file: ' . $e->getMessage());
            }
            return true;
        } finally {
            $restore();
        }
    }

}

IonDisk::init();
