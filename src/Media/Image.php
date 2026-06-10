<?php

declare(strict_types=1);

namespace Ions\Media;

use Intervention\Image\Exceptions\RuntimeException as InterventionException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Ions\Foundation\Kernel;
use Throwable;

/**
 * Ergonomic wrapper over Intervention Image v3.
 *
 * Restores the resize / crop / cover / watermark / encode capabilities that
 * were dropped when verot/class.upload.php was removed in 3.0.0, behind a thin,
 * fluent facade that delegates to Intervention's {@see ImageManager}.
 *
 * The processing driver is read from `config('media.driver')` (defaults to the
 * GD driver, which ships with most PHP builds). Set it to `imagick` to use the
 * Imagick driver instead.
 *
 * Typical usage:
 *
 *     use Ions\Media\Image;
 *
 *     Image::read($path)
 *         ->cover(300, 300)
 *         ->quality(80)
 *         ->save($thumbPath);
 *
 * Every failure surfaces as {@see ImageException} rather than a raw Intervention
 * error.
 */
final class Image
{
    private ImageInterface $image;

    private ?int $quality = null;

    private function __construct(ImageInterface $image)
    {
        $this->image = $image;
    }

    /**
     * Load an image from a file path.
     *
     * @throws ImageException when the path is missing/unreadable or undecodable
     */
    public static function read(string $path): self
    {
        if (!is_file($path)) {
            throw new ImageException(sprintf('Image file not found: %s', $path));
        }

        return self::decode($path);
    }

    /**
     * Alias of {@see read()}.
     *
     * @throws ImageException
     */
    public static function make(string $path): self
    {
        return self::read($path);
    }

    /**
     * Decode an image from raw binary bytes.
     *
     * @throws ImageException when the bytes are not a decodable image
     */
    public static function fromString(string $binary): self
    {
        return self::decode($binary);
    }

    /**
     * Resolve the configured ImageManager and decode the given input.
     *
     * @param string $input file path or raw binary
     *
     * @throws ImageException
     */
    private static function decode(string $input): self
    {
        try {
            return new self(self::manager()->read($input));
        } catch (InterventionException $e) {
            throw new ImageException('Unable to read image: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the ImageManager for the configured driver.
     *
     * Falls back to the GD driver when no kernel config is available (e.g.
     * the class is used in isolation, outside a booted host application).
     */
    private static function manager(): ImageManager
    {
        $driver = 'gd';
        try {
            // Kernel::config() is strictly typed Config but holds [] until the
            // host app boots; guard so the helper can be used standalone too.
            $config = Kernel::config();
            $configured = $config->get('media.driver', 'gd');
            if (is_string($configured) && $configured !== '') {
                $driver = $configured;
            }
        } catch (Throwable) {
            // not booted (or no media config) — keep the GD default
        }

        try {
            return strtolower($driver) === 'imagick'
                ? ImageManager::imagick()
                : ImageManager::gd();
        } catch (InterventionException $e) {
            throw new ImageException('Unable to initialise image driver: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resize to exact dimensions (does not preserve aspect ratio).
     */
    public function resize(int $w, int $h): self
    {
        $this->image->resize($w, $h);

        return $this;
    }

    /**
     * Aspect-ratio preserving resize. Pass a width and/or a height; the missing
     * side is computed from the source ratio.
     */
    public function scale(?int $w = null, ?int $h = null): self
    {
        $this->image->scale($w, $h);

        return $this;
    }

    /**
     * Crop a $w x $h region starting at offset ($x, $y) from the top-left.
     */
    public function crop(int $w, int $h, int $x = 0, int $y = 0): self
    {
        $this->image->crop($w, $h, $x, $y);

        return $this;
    }

    /**
     * Resize and crop so the image fills the $w x $h box (no distortion, may
     * trim edges).
     */
    public function cover(int $w, int $h): self
    {
        $this->image->cover($w, $h);

        return $this;
    }

    /**
     * Place a watermark image on top of the current image.
     *
     * @param string $watermarkPath path to the watermark image file
     * @param string $position       e.g. top-left, center, bottom-right
     * @param int    $opacity        0–100
     *
     * @throws ImageException when the watermark cannot be read/placed
     */
    public function watermark(string $watermarkPath, string $position = 'bottom-right', int $opacity = 100): self
    {
        if (!is_file($watermarkPath)) {
            throw new ImageException(sprintf('Watermark file not found: %s', $watermarkPath));
        }

        try {
            $this->image->place($watermarkPath, $position, 0, 0, $opacity);
        } catch (InterventionException $e) {
            throw new ImageException('Unable to apply watermark: ' . $e->getMessage(), 0, $e);
        }

        return $this;
    }

    /**
     * Set the encoding quality (0–100) used by the next {@see save()} /
     * {@see toString()} call. Ignored by formats that have no quality concept.
     */
    public function quality(int $q): self
    {
        $this->quality = $q;

        return $this;
    }

    /**
     * Alias of {@see quality()} kept for an explicit, encode-oriented name.
     */
    public function encodeBy(int $q): self
    {
        return $this->quality($q);
    }

    /**
     * Encode by the target path's extension and write the file.
     *
     * @param int|null $quality overrides any quality set via {@see quality()}
     *
     * @throws ImageException for an unsupported extension or a write failure
     */
    public function save(string $path, ?int $quality = null): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ImageException(sprintf('Cannot determine image format from path: %s', $path));
        }

        $options = $this->qualityOptions($quality);

        try {
            $encoded = $this->image->encodeByExtension($extension, ...$options);
            $encoded->save($path);
        } catch (InterventionException $e) {
            throw new ImageException(
                sprintf('Unable to save image as "%s": %s', $extension, $e->getMessage()),
                0,
                $e
            );
        }

        return is_file($path);
    }

    /**
     * Encode the image to a binary string in the given format (e.g. jpg, png,
     * webp).
     *
     * @throws ImageException for an unsupported format
     */
    public function toString(string $format = 'jpg', int $quality = 90): string
    {
        try {
            return $this->image->encodeByExtension($format, quality: $quality)->toString();
        } catch (InterventionException $e) {
            throw new ImageException(
                sprintf('Unable to encode image as "%s": %s', $format, $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function width(): int
    {
        return $this->image->width();
    }

    public function height(): int
    {
        return $this->image->height();
    }

    /**
     * The underlying Intervention image, for advanced operations not exposed by
     * this wrapper.
     */
    public function intervention(): ImageInterface
    {
        return $this->image;
    }

    /**
     * Build the encoder option list, applying an explicit override, then the
     * fluent quality, if either is set.
     *
     * @return array<string,int>
     */
    private function qualityOptions(?int $override): array
    {
        $quality = $override ?? $this->quality;

        return $quality === null ? [] : ['quality' => $quality];
    }
}
