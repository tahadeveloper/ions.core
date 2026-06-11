<?php

/**
 * Regression tests for the arbitrary-file-deletion / path-traversal hardening
 * of the legacy native delete branches:
 *   - IonUpload::remove() / IonUpload::update() (old filename from the request)
 *   - IonDisk::delete() / IonDisk::deleteDirectory()
 *
 * Each exploit case plants a sentinel OUTSIDE the uploads/disk root and asserts
 * a traversal payload cannot delete it, while legitimate basename deletes still
 * work.
 */

use Ions\Bundles\IonDisk;
use Ions\Bundles\IonUpload;
use Ions\Foundation\Kernel;
use Ions\Support\Request;

/** Plant a sentinel file in a directory that is NOT the uploads/disk root. */
function plantSentinel(): array
{
    $root = sys_get_temp_dir() . '/ion_trav_root_' . bin2hex(random_bytes(4));
    $uploads = $root . '/public/uploads';
    mkdir($uploads, 0755, true);

    // Sentinel sits at $root/.env — two levels above the uploads dir.
    $sentinel = $root . '/.env';
    file_put_contents($sentinel, 'SECRET=keep-me');

    return [$root, $uploads, $sentinel];
}

function cleanupSentinel(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
}

test('IonUpload::remove() refuses a traversal filename and the sentinel survives', function () {
    bootFixtureKernel();
    [$root, $uploads, $sentinel] = plantSentinel();

    // old_image = ../../.env  →  $uploads/../../.env  resolves to $root/.env
    IonUpload::remove('../../.env', $uploads);

    expect(file_exists($sentinel))->toBeTrue(); // NOT deleted

    cleanupSentinel($root);
});

test('IonUpload::remove() still deletes a legitimate basename', function () {
    bootFixtureKernel();
    [$root, $uploads] = plantSentinel();

    $victim = $uploads . '/photo.jpg';
    file_put_contents($victim, 'data');

    IonUpload::remove('photo.jpg', $uploads);

    expect(file_exists($victim))->toBeFalse(); // legitimately removed

    cleanupSentinel($root);
});

test('IonUpload::update() does not delete an arbitrary file named in the request', function () {
    bootFixtureKernel();
    [$root, $uploads, $sentinel] = plantSentinel();

    // The request supplies the old filename to remove — an attacker sets it to
    // a traversal payload so a valid new upload triggers deletion of $root/.env.
    $request = Request::create('/upload', 'POST', ['old_image' => '../../.env']);
    (new ReflectionProperty(Kernel::class, 'request'))->setValue(null, $request);

    $tmp = tempnam(sys_get_temp_dir(), 'upd');
    // Minimal valid JPEG (finfo => image/jpeg) so the new upload passes validation.
    file_put_contents($tmp, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, 'new.jpg', null, null, true);

    IonUpload::update('old_image', 'old_original', $file, $uploads);

    expect(file_exists($sentinel))->toBeTrue(); // sentinel survives the update

    cleanupSentinel($root);
});

test('IonDisk::delete() refuses a traversal path and the sentinel survives', function () {
    bootFixtureKernel();
    IonDisk::disk('local');
    [$root, $uploads, $sentinel] = plantSentinel();

    // Point the local disk root at $uploads so the sentinel is outside the root.
    config(['filesystem.disks.local.root' => $uploads]);
    IonDisk::disk('local');

    $payload = $uploads . '/../../.env';
    try {
        IonDisk::delete($payload);
    } catch (\RuntimeException) {
        // a rejection is acceptable; the sentinel must remain either way
    }

    expect(file_exists($sentinel))->toBeTrue(); // NOT deleted

    cleanupSentinel($root);
});

test('IonDisk::delete() still deletes a legitimate file inside the disk root', function () {
    bootFixtureKernel();
    [$root, $uploads] = plantSentinel();

    config(['filesystem.disks.local.root' => $uploads]);
    IonDisk::disk('local');

    $victim = $uploads . '/keep.txt';
    file_put_contents($victim, 'data');

    IonDisk::delete($victim);

    expect(file_exists($victim))->toBeFalse(); // legitimately removed

    cleanupSentinel($root);
});
