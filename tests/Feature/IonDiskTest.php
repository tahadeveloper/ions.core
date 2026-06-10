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

/** Minimal valid JPEG payload (finfo => image/jpeg) for content-validation. */
function tinyJpegBytesForDisk(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

test('IonDisk::put() accepts a .jpg upload and stores it with a .jpg extension', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('photo.jpg', tinyJpegBytesForDisk());

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeFalsy()
        ->and($result['filename'])->toEndWith('.jpg');

    expect(file_exists($dest . '/' . $result['filename']))->toBeTrue();

    removeTempDest($dest);
});

test('IonDisk::put() rejects a .jpg whose content is PHP source (magic-bytes gate)', function () {
    bootFixtureKernel();

    $dest = makeTempDest();
    $file = makeUploadedForDisk('innocent.jpg', '<?php system($_GET["c"]);');

    $result = IonDisk::put($file, $dest);

    expect($result['error'])->toBeTruthy()
        ->and($result['message'])->toContain('content');
    expect(glob($dest . '/*'))->toBe([]); // nothing written

    removeTempDest($dest);
});

test('IonDisk::putFile() rejects raw PHP content named .png (buffer magic-bytes gate)', function () {
    bootFixtureKernel();
    IonDisk::disk('local'); // re-init the flysystem against the booted fixture config

    $result = IonDisk::putFile('<?php echo 1;', 'innocent.png', 'ion_disk_putfile_' . bin2hex(random_bytes(4)));

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('content');
});

test('IonDisk::putFile() accepts a real PNG named .png', function () {
    bootFixtureKernel();
    IonDisk::disk('local');

    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = 'ion_disk_putfile_' . bin2hex(random_bytes(4));

    $result = IonDisk::putFile($png, 'pixel.png', $dir);

    expect($result)->toHaveKey('upload_name')
        ->and($result['upload_name'])->toEndWith('.png');

    @unlink(sys_get_temp_dir() . '/' . $dir . '/' . $result['upload_name']);
    @rmdir(sys_get_temp_dir() . '/' . $dir);
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
