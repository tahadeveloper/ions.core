<?php

declare(strict_types=1);

use Ions\Media\Image;
use Ions\Media\ImageException;

/**
 * Create a small JPEG test image at runtime so no binary fixtures are committed.
 */
function makeTestImage(int $w = 100, int $h = 80, string $ext = 'jpg'): string
{
    $path = tempnam(sys_get_temp_dir(), 'ions_img') . '.' . $ext;
    $im = imagecreatetruecolor($w, $h);
    // fill with a recognisable colour so watermark/encode have content
    $blue = imagecolorallocate($im, 30, 90, 200);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $blue);
    match ($ext) {
        'png' => imagepng($im, $path),
        'webp' => imagewebp($im, $path),
        default => imagejpeg($im, $path),
    };
    imagedestroy($im);

    return $path;
}

beforeEach(function () {
    if (!extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is not loaded.');
    }
});

test('read returns an Image and reports source dimensions', function () {
    $src = makeTestImage(100, 80);
    $im = Image::read($src);
    expect($im)->toBeInstanceOf(Image::class)
        ->and($im->width())->toBe(100)
        ->and($im->height())->toBe(80);
    @unlink($src);
});

test('make is an alias for read', function () {
    $src = makeTestImage(60, 40);
    expect(Image::make($src)->width())->toBe(60);
    @unlink($src);
});

test('resize changes dimensions', function () {
    $src = makeTestImage(100, 80);
    $out = Image::read($src)->resize(50, 40);
    expect($out->width())->toBe(50)->and($out->height())->toBe(40);
    @unlink($src);
});

test('scale preserves aspect ratio', function () {
    $src = makeTestImage(100, 80);
    // scale to width 50 -> height 40 for a 100x80 src
    $out = Image::read($src)->scale(50);
    expect($out->width())->toBe(50)->and($out->height())->toBe(40);
    @unlink($src);
});

test('crop extracts a sub region', function () {
    $src = makeTestImage(100, 80);
    $out = Image::read($src)->crop(30, 20, 5, 5);
    expect($out->width())->toBe(30)->and($out->height())->toBe(20);
    @unlink($src);
});

test('cover fills the target box', function () {
    $src = makeTestImage(100, 80);
    $out = Image::read($src)->cover(40, 40);
    expect($out->width())->toBe(40)->and($out->height())->toBe(40);
    @unlink($src);
});

test('fromString decodes raw bytes', function () {
    $src = makeTestImage(70, 50);
    $binary = file_get_contents($src);
    $im = Image::fromString($binary);
    expect($im->width())->toBe(70)->and($im->height())->toBe(50);
    @unlink($src);
});

test('watermark places another image without error', function () {
    $src = makeTestImage(100, 80);
    $mark = makeTestImage(20, 20);
    $out = Image::read($src)->watermark($mark, 'bottom-right', 50);
    // dimensions unchanged
    expect($out->width())->toBe(100)->and($out->height())->toBe(80);
    @unlink($src);
    @unlink($mark);
});

test('save writes a file in the target format (png)', function () {
    $src = makeTestImage(100, 80);
    $out = tempnam(sys_get_temp_dir(), 'ions_out') . '.png';
    $ok = Image::read($src)->resize(40, 40)->save($out);
    expect($ok)->toBeTrue()
        ->and(file_exists($out))->toBeTrue();
    $info = getimagesize($out);
    expect($info)->not->toBeFalse()
        ->and($info['mime'])->toBe('image/png');
    @unlink($src);
    @unlink($out);
});

test('toString encodes to the requested format', function () {
    $src = makeTestImage(50, 50);
    $bytes = Image::read($src)->toString('png');
    // PNG signature
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    @unlink($src);
});

test('quality + save round trips as jpeg', function () {
    $src = makeTestImage(80, 80);
    $out = tempnam(sys_get_temp_dir(), 'ions_q') . '.jpg';
    $ok = Image::read($src)->quality(60)->save($out);
    expect($ok)->toBeTrue();
    $info = getimagesize($out);
    expect($info['mime'])->toBe('image/jpeg');
    @unlink($src);
    @unlink($out);
});

test('reading a missing path throws ImageException', function () {
    Image::read('/no/such/file/at/all.jpg');
})->throws(ImageException::class);

test('reading a non-image throws ImageException', function () {
    $bogus = tempnam(sys_get_temp_dir(), 'ions_bad') . '.jpg';
    file_put_contents($bogus, 'this is not an image');
    // GD emits a native warning for undecodable data before Intervention
    // converts the failure into an exception; silence it for a clean run.
    set_error_handler(static fn () => true);
    try {
        Image::read($bogus);
    } finally {
        restore_error_handler();
        @unlink($bogus);
    }
})->throws(ImageException::class);

test('save with an unsupported extension throws ImageException', function () {
    $src = makeTestImage(40, 40);
    try {
        Image::read($src)->save(sys_get_temp_dir() . '/out.xyz');
    } finally {
        @unlink($src);
    }
})->throws(ImageException::class);
