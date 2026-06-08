<?php

use Ions\Bundles\IonUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;

function makeUploaded(string $clientName, string $content = 'x'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmp, $content);
    // test mode = true (5th argument) bypasses is_uploaded_file check
    return new UploadedFile($tmp, $clientName, null, null, true);
}

test('rejects a .php upload (RCE gate) and writes nothing', function () {
    bootFixtureKernel();
    $dest = sys_get_temp_dir() . '/ion_upl_' . bin2hex(random_bytes(4));
    mkdir($dest);
    $out = IonUpload::store(makeUploaded('shell.php', '<?php echo 1;'), $dest)->response();
    expect($out['error'])->toBe(1)
        ->and($out['message'])->toContain('not allowed');
    expect(glob($dest . '/*'))->toBe([]); // nothing written
});

test('accepts a .jpg upload and stores it with a random safe name', function () {
    bootFixtureKernel();
    $dest = sys_get_temp_dir() . '/ion_upl_' . bin2hex(random_bytes(4));
    mkdir($dest);
    $out = IonUpload::store(makeUploaded('photo.jpg'), $dest)->response();
    expect($out['error'])->toBe(0)
        ->and($out['store_name'])->toEndWith('.jpg');
    expect(file_exists($dest . '/' . $out['store_name']))->toBeTrue();
});
