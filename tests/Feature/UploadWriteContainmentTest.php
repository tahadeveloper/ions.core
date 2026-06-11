<?php

/**
 * FINDING 1 / 2 / 3 regression tests — WRITE / MOVE / COPY / DOWNLOAD sinks.
 *
 * The upload target DIRECTORY (and move/copy/download endpoints) are
 * request-controllable and were written without containment. A `../../`-escaping
 * target must be rejected with nothing written outside the intended root, while
 * legitimate (existing) subdir targets keep working.
 *
 * Honors FINDING 8: a write-side guard canonicalizes the PARENT dir and must
 * NOT pass when realpath() of a `..`-bearing path is false.
 */

use Ions\Bundles\IonDisk;
use Ions\Bundles\IonUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;

beforeEach(fn () => bootFixtureKernel());

function tinyJpeg(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

function uploadedJpeg(): UploadedFile
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'wct');
    file_put_contents($tmp, tinyJpeg());
    return new UploadedFile($tmp, 'photo.jpg', null, null, true);
}

// ── FINDING 1: write sinks ──────────────────────────────────────────────────

test('IonUpload::store() rejects a ../../ escaping target directory and writes nothing outside', function () {
    [$root, $sentinelDir] = makeWriteRoots();

    // Target escapes the uploads root up to $root/escaped.
    $target = $sentinelDir . '/sub/../../escaped';

    $out = IonUpload::store(uploadedJpeg(), $target)->response();

    expect($out['error'])->toBe(1);
    expect(is_dir($root . '/escaped'))->toBeFalse();
    expect(glob($root . '/escaped/*') ?: [])->toBe([]);

    cleanupWriteRoots($root);
});

test('IonUpload::store() still stores into a legitimate (existing) subdir target', function () {
    [$root, $uploads] = makeWriteRoots();
    $sub = $uploads . '/avatars';
    mkdir($sub, 0755, true);

    $out = IonUpload::store(uploadedJpeg(), $sub)->response();

    expect($out['error'])->toBe(0);
    expect(file_exists($sub . '/' . $out['store_name']))->toBeTrue();

    cleanupWriteRoots($root);
});

test('IonDisk::put() rejects a ../../ escaping target directory and writes nothing outside', function () {
    [$root, $uploads] = makeWriteRoots();
    $target = $uploads . '/sub/../../escaped';

    $result = IonDisk::put(uploadedJpeg(), $target);

    expect($result['error'])->toBeTruthy();
    expect(is_dir($root . '/escaped'))->toBeFalse();

    cleanupWriteRoots($root);
});

test('IonDisk::putFile() rejects a ../../ escaping target directory', function () {
    IonDisk::disk('local');
    [$root, $uploads] = makeWriteRoots();

    // putFile joins $userProvidedPath/$name and writes via Flysystem rooted at
    // the local disk root (sys_get_temp_dir). A traversal user path escapes it.
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $result = IonDisk::putFile($png, 'pixel.png', '../../escaped_putfile');

    expect($result)->toHaveKey('error');

    cleanupWriteRoots($root);
});

// ── FINDING 3: download destination ─────────────────────────────────────────

test('IonDisk::download() refuses a traversal destination path', function () {
    IonDisk::disk('local');
    $diskRoot = sys_get_temp_dir();
    $srcName = 'wct_dl_src_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($diskRoot . '/' . $srcName, 'data');

    // Destination tries to escape allowed roots with a traversal segment.
    $dest = $diskRoot . '/sub/../../../../../../etc/wct_escape_' . bin2hex(random_bytes(4));

    $result = IonDisk::download($srcName, $dest);

    expect($result)->toHaveKey('error');
    expect(file_exists('/etc/wct_escape'))->toBeFalse();

    @unlink($diskRoot . '/' . $srcName);
});

test('IonDisk::download() still writes to a legitimate destination under temp', function () {
    IonDisk::disk('local');
    $diskRoot = sys_get_temp_dir();
    $srcName = 'wct_dl_src_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($diskRoot . '/' . $srcName, 'hello');

    $dest = sys_get_temp_dir() . '/wct_dl_dest_' . bin2hex(random_bytes(4)) . '.txt';
    $result = IonDisk::download($srcName, $dest);

    expect($result)->toBe(['success' => true]);
    expect(file_get_contents($dest))->toBe('hello');

    @unlink($diskRoot . '/' . $srcName);
    @unlink($dest);
});

// ── helpers ─────────────────────────────────────────────────────────────────

function makeWriteRoots(): array
{
    $root = sys_get_temp_dir() . '/ion_wct_root_' . bin2hex(random_bytes(4));
    $uploads = $root . '/public/uploads';
    mkdir($uploads, 0755, true);
    return [$root, $uploads];
}

function cleanupWriteRoots(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($root);
}
