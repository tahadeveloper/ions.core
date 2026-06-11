<?php

use Ions\Bundles\IonDisk;
use Ions\Bundles\IonUpload;
use Ions\Filesystem\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

beforeEach(function () {
    bootFixtureKernel();
    IonDisk::disk('local'); // pin IonDisk to the fixture's default disk
});

function makeUploadedForFake(string $clientName, string $content): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'fkupl');
    file_put_contents($tmp, $content);

    return new UploadedFile($tmp, $clientName, null, null, true);
}

/** Minimal valid JPEG payload (finfo => image/jpeg) for content-validation. */
function tinyJpegBytesForFake(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

// The 8.4 caveat, flipped: IonDisk/IonUpload now resolve their disks through
// the shared FilesystemManager, so Storage::fake() intercepts their writes too.

test('Storage::fake() intercepts IonDisk::putFile() — content lands in the fake, not on the real disk', function () {
    $fake = Storage::fake();

    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = 'ion_fake_putfile_' . bin2hex(random_bytes(4));

    $result = IonDisk::putFile($png, 'pixel.png', $dir);

    expect($result)->toHaveKey('upload_name');

    $fake->assertStored($dir . '/' . $result['upload_name']);

    // The configured local disk (sys temp dir) was never touched.
    expect(is_dir(sys_get_temp_dir() . '/' . $dir))->toBeFalse();
});

test('Storage::fake() intercepts IonDisk::put() — the upload lands in the fake, the destination dir stays empty', function () {
    $fake = Storage::fake();

    $dest = sys_get_temp_dir() . '/ion_fake_put_' . bin2hex(random_bytes(4));
    mkdir($dest, 0755, true);

    $result = IonDisk::put(makeUploadedForFake('photo.jpg', tinyJpegBytesForFake()), $dest);

    expect($result['error'])->toBeFalsy()
        ->and($result['filename'])->toEndWith('.jpg');

    $fake->assertStored($dest . '/' . $result['filename']);

    // Real destination directory untouched.
    expect(glob($dest . '/*'))->toBe([]);
    @rmdir($dest);
});

test('Storage::fake() does not weaken IonDisk::put() validation — bad uploads are still rejected', function () {
    $fake = Storage::fake();

    $dest = sys_get_temp_dir() . '/ion_fake_rej_' . bin2hex(random_bytes(4));
    mkdir($dest, 0755, true);

    $php = IonDisk::put(makeUploadedForFake('shell.php', '<?php echo 1;'), $dest);
    $forged = IonDisk::put(makeUploadedForFake('innocent.jpg', '<?php system($_GET["c"]);'), $dest);

    expect($php['error'])->toBeTruthy()
        ->and($forged['error'])->toBeTruthy();

    expect(Storage::files())->toBe([]); // nothing reached the fake either
    @rmdir($dest);
});

test('Storage::fake() intercepts IonUpload::store() — store_name lands in the fake, real dir stays empty', function () {
    $fake = Storage::fake();

    $dest = sys_get_temp_dir() . '/ion_fake_upl_' . bin2hex(random_bytes(4));
    mkdir($dest, 0755, true);

    $out = IonUpload::store(makeUploadedForFake('photo.jpg', tinyJpegBytesForFake()), $dest)->response();

    expect($out['error'])->toBe(0)
        ->and($out['store_name'])->toEndWith('.jpg');

    $fake->assertStored($dest . '/' . $out['store_name']);

    // Real destination directory untouched.
    expect(glob($dest . '/*'))->toBe([]);
    @rmdir($dest);
});

test('read-style Storage calls see IonDisk writes made against the fake', function () {
    Storage::fake();

    $dir = 'ion_fake_read_' . bin2hex(random_bytes(4));
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $result = IonDisk::putFile($png, 'pixel.png', $dir);

    expect(Storage::files($dir))->toBe([$dir . '/' . $result['upload_name']])
        ->and(Storage::exists($dir . '/' . $result['upload_name']))->toBeTrue();
});

// Default-disk resolution chain: IonDisk keeps reading the legacy
// filesystem.disks.default key (hosts feed it from FILESYSTEM_DISK env).

test("IonDisk::disk('') resolves the default type from filesystem.disks.default", function () {
    config(['filesystem.disks.default' => 's3']);
    IonDisk::disk('');
    expect(IonDisk::getType())->toBe('s3');

    config(['filesystem.disks.default' => 'local']);
    IonDisk::disk('');
    expect(IonDisk::getType())->toBe('local');
});
