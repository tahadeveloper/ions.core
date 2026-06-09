<?php

use Ions\Bundles\IonDisk;

beforeEach(fn () => bootFixtureKernel());

test('download() reads a stored file and writes it to the local destination', function () {
    // Arrange: the local disk root is sys_get_temp_dir() (from fixture filesystem.php).
    // IonDisk::init() uses config('filesystem.disks.local.root') as the Flysystem base.
    // So a stored path of 'iondisk_dl_src_XXXX.txt' resolves under sys_get_temp_dir().
    $diskRoot = sys_get_temp_dir();
    $srcName = 'iondisk_dl_src_' . bin2hex(random_bytes(4)) . '.txt';
    $srcAbsPath = $diskRoot . '/' . $srcName;
    file_put_contents($srcAbsPath, 'hello-content');

    // Destination: a temp path outside the disk root (just a plain path, not yet existing).
    $dest = sys_get_temp_dir() . '/iondisk_dl_dest_' . bin2hex(random_bytes(4)) . '.txt';

    // Act: download should READ $srcName from the disk and WRITE to $dest.
    $result = IonDisk::download($srcName, $dest);

    // Assert: destination file was created with the correct contents.
    expect(file_exists($dest))->toBeTrue('destination file should have been created by download()')
        ->and(file_get_contents($dest))->toBe('hello-content', 'destination contents should match the stored file');

    // The source file on the disk must still exist (download = copy, not move).
    expect(file_exists($srcAbsPath))->toBeTrue('source file on the disk must not be deleted by download()');

    // Return value should indicate success.
    expect($result)->toBe(['success' => true]);

    // Cleanup.
    @unlink($srcAbsPath);
    @unlink($dest);
});

test('download() returns error array when the stored file does not exist', function () {
    $result = IonDisk::download('nonexistent/ghost_' . bin2hex(random_bytes(4)) . '.txt', '/tmp/nowhere.txt');

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toBe('File not found');
});
