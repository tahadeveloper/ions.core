<?php

use Ions\Bundles\IonDisk;
use Symfony\Component\HttpFoundation\File\UploadedFile;

function makeUploadedForDisk(string $clientName, string $content = 'x'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'dsk');
    file_put_contents($tmp, $content);
    // test mode = true (5th argument) bypasses is_uploaded_file check
    return new UploadedFile($tmp, $clientName, null, null, true);
}

function makeTempDest(): string
{
    $dest = sys_get_temp_dir() . '/ion_disk_' . bin2hex(random_bytes(4));
    mkdir($dest, 0755, true);
    return $dest;
}

function removeTempDest(string $dest): void
{
    foreach (glob($dest . '/*') as $file) {
        unlink($file);
    }
    if (is_dir($dest)) {
        rmdir($dest);
    }
}

test('IonDisk::put() rejects a .php upload (RCE gate) and writes nothing to disk', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('shell.php', '<?php echo 1;');

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeTruthy()
        ->and($result['message'])->toContain('not allowed');

    expect(glob($dest . '/*'))->toBe([]); // nothing written

    removeTempDest($dest);
});

test('IonDisk::put() accepts a .jpg upload and stores it with a .jpg extension', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('photo.jpg', 'fakejpegdata');

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeFalsy()
        ->and($result['filename'])->toEndWith('.jpg');

    expect(file_exists($dest . '/' . $result['filename']))->toBeTrue();

    removeTempDest($dest);
});

test('IonDisk::put() rejects .phtm extension (newly-denied)', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('evil.phtm', '<?php echo 1;');

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeTruthy();
    expect(glob($dest . '/*'))->toBe([]);

    removeTempDest($dest);
});

test('IonDisk::put() rejects .inc extension (newly-denied)', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('config.inc', 'data');

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeTruthy();
    expect(glob($dest . '/*'))->toBe([]);

    removeTempDest($dest);
});
