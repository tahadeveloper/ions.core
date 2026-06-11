<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Ions\Filesystem\Storage as ManagedStorage;
use Ions\Foundation\Kernel;
use Ions\Foundation\Singleton;
use Ions\Media\Image;
use Ions\Media\ImageException;
use Ions\Security\UploadValidator;
use Ions\Support\Storage;
use Ions\Support\Str;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Upload handling (validation + store/move/remove).
 *
 * Storage writes flow through the shared {@see \Ions\Filesystem\FilesystemManager}
 * when a disk has been swapped in by Ions\Filesystem\Storage::fake(), so tests
 * intercept IonUpload writes; real requests keep their native move semantics.
 */
class IonUpload extends Singleton
{
    private static mixed $output;

    /**
     * The faked default disk when Storage::fake() is active, null otherwise.
     */
    private static function fakeDisk(): ?Filesystem
    {
        try {
            $manager = ManagedStorage::manager();
            $name = $manager->getDefaultDriver();

            return $manager->isOverridden($name) ? $manager->disk($name) : null;
        } catch (Throwable) {
            // No booted container (legacy direct usage) — no fake to honour.
            return null;
        }
    }

    /**
     * Existence check that honours an active Storage::fake() disk.
     */
    private static function existsOnDisk(string $path): bool
    {
        $fake = self::fakeDisk();

        return $fake !== null ? $fake->has($path) : Storage::exists($path);
    }

    public static function store(mixed $file, string $path, array $options = []): self
    {
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            self::$output = ['error' => 1, 'message' => 'No file to upload'];
            return new self();
        }

        $allowed = $options['allowed'] ?? config('app.uploads.allowed', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip']);
        unset($options['allowed']);
        $validator = new UploadValidator($allowed, (array) config('app.uploads.mime_map', []));

        $originalName = $file->getClientOriginalName();
        if (!$validator->isAllowed($originalName)) {
            self::$output = ['error' => 1, 'message' => 'File extension not allowed'];
            return new self();
        }

        // Magic-bytes gate: the actual content must agree with the claimed
        // extension (e.g. PHP source named .jpg is rejected) BEFORE the move.
        if (!$validator->isContentValid($file->getPathname(), $originalName)) {
            self::$output = ['error' => 1, 'message' => 'File content does not match its extension'];
            return new self();
        }

        // Optional image post-processing. When an `image` option is supplied,
        // the stored file is run through Ions\Media\Image after the move. This
        // is entirely opt-in — non-image uploads never touch the media stack.
        $image = $options['image'] ?? null;
        unset($options['image']);

        $ext = $validator->safeExtension($originalName);
        $randomName = Str::random(15);
        $storeName = $randomName . '.' . $ext;

        // Storage::fake() active: write the (already validated) upload through
        // the faked manager disk instead of moving it onto the real disk. The
        // image hook is skipped — it operates on a real stored path.
        if (($fake = self::fakeDisk()) !== null) {
            $fake->write($path . '/' . $storeName, (string) file_get_contents($file->getPathname()));
            self::$output = [
                'error' => 0,
                'message' => 'file uploaded',
                'original_name' => $originalName,
                'store_name' => $storeName,
            ];

            return new self();
        }

        try {
            $file->move($path, $storeName);
            if (is_callable($image)) {
                $image(Image::read($path . '/' . $storeName), $path . '/' . $storeName);
            }
            self::$output = [
                'error' => 0,
                'message' => 'file uploaded',
                'original_name' => $originalName,
                'store_name' => $storeName,
            ];
        } catch (FileException | ImageException $e) {
            self::$output = ['error' => 1, 'message' => $e->getMessage()];
        }

        return new self();
    }

    /**
     * Reject any filename that is not a bare basename.
     *
     * A stored upload name is always a single path segment (random stem +
     * extension). Anything containing a directory separator or a `..` segment
     * — e.g. an attacker-supplied `old_image=../../../.env` flowing in through
     * update() — is a path-traversal attempt and must never reach unlink().
     */
    private static function isSafeFileName(string $fileName): bool
    {
        if ($fileName === '' || str_contains($fileName, '/') || str_contains($fileName, '\\') || str_contains($fileName, "\0")) {
            return false;
        }

        return $fileName !== '..' && basename($fileName) === $fileName;
    }

    public static function remove(string $fileName, string $path): self
    {
        // Path-traversal guard: the filename must be a bare basename. This
        // single check neutralises both the faked-disk and native branches and
        // the request-controlled value passed in from update().
        if (!self::isSafeFileName($fileName)) {
            return new self();
        }

        if (($fake = self::fakeDisk()) !== null) {
            if ($fake->has($path . '/' . $fileName)) {
                $fake->delete($path . '/' . $fileName);
            }

            return new self();
        }

        // Belt to the basename brace: only unlink when the resolved real path
        // stays inside the uploads directory ($path is the uploads root here).
        $target = $path . '/' . $fileName;
        if (file_exists($target) && self::isInside($path, $target)) {
            unlink($target);
        }

        return new self();
    }

    /**
     * True when $target's canonical real path is contained within $root.
     */
    private static function isInside(string $root, string $target): bool
    {
        $realRoot = realpath($root);
        $realTarget = realpath($target);
        if ($realRoot === false || $realTarget === false) {
            return false;
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($realTarget . DIRECTORY_SEPARATOR, $realRoot);
    }

    public static function moveUrl(string $image_url, string $destination, $old_destination = 'dump'): self
    {
        $image_ext = null;
        if ($image_url) {
            $url_array = explode('/', $image_url);
            $count_url_array = count($url_array);
            $file_name = $url_array[$count_url_array - 1];
            if (str_contains($image_url, $old_destination) && self::existsOnDisk(Path::files($old_destination . '/' . $file_name))) {
                static::moveLocal($old_destination, $destination, $file_name);
                $image_ext = $url_array[$count_url_array - 1];
            }
            if ((str_contains($image_url, $old_destination) || str_contains($image_url, $destination)) && self::existsOnDisk(Path::files($destination . '/' . $file_name))) {
                $image_ext = $url_array[$count_url_array - 1];
            }
        }
        self::$output = $image_ext;

        return new self();
    }

    public static function moveLocal($from, $to, $file_name, $new_name = null): self
    {
        $source = Path::files($from . '/' . $file_name);
        $target = Path::files($to . '/' . ($new_name ?? $file_name));

        $result = false;
        if (($fake = self::fakeDisk()) !== null) {
            if ($fake->has($source)) {
                $fake->move($source, $target);
                $result = true;
            }
        } elseif (Storage::exists($source)) {
            $result = Storage::move($source, $target);
        }
        self::$output = $result;

        return new self();
    }

    /**
     * Read a single input value from the request without the deprecated
     * Request::get(): prefer the POST/body bag, fall back to the query bag.
     */
    private static function requestValue(mixed $request, string $key): mixed
    {
        if ($request->request->has($key)) {
            return $request->request->get($key);
        }

        return $request->query->get($key);
    }

    public static function update($file_name, $file_original_name, $file, $path, array $options = []): self
    {
        $request = Kernel::request();
        // The old filename to delete comes straight from the request; reduce it
        // to a bare basename at the boundary so a traversal payload (e.g.
        // `../../../.env`) can never reach remove() — which also re-validates.
        $image_name = self::requestValue($request, $file_name);
        if (is_string($image_name) && $image_name !== '') {
            $image_name = basename($image_name);
        }
        $original_name = self::requestValue($request, $file_original_name);
        self::$output['error'] = 0;
        if ($file) {
            $upload_file = static::store($file, $path, $options);
            $upload_result = $upload_file::$output;
            if ((int)$upload_result['error'] === 0) {
                if ($image_name) {
                    static::remove($image_name, $path);
                }
                $image_name = $upload_result['store_name'];
                $original_name = $upload_result['original_name'];
            }
        }
        self::$output['store_name'] = $image_name;
        self::$output['original_name'] = $original_name;
        return new self();
    }

    public function response()
    {
        return self::$output;
    }

}
