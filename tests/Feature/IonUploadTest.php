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

/** Minimal valid JPEG payload (finfo => image/jpeg) for content-validation. */
function tinyJpegBytes(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
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
    $out = IonUpload::store(makeUploaded('photo.jpg', tinyJpegBytes()), $dest)->response();
    expect($out['error'])->toBe(0)
        ->and($out['store_name'])->toEndWith('.jpg');
    expect(file_exists($dest . '/' . $out['store_name']))->toBeTrue();
});

test('rejects a .jpg whose content is PHP source (magic-bytes gate) and writes nothing', function () {
    bootFixtureKernel();
    $dest = sys_get_temp_dir() . '/ion_upl_' . bin2hex(random_bytes(4));
    mkdir($dest);
    $out = IonUpload::store(makeUploaded('innocent.jpg', '<?php system($_GET["c"]);'), $dest)->response();
    expect($out['error'])->toBe(1)
        ->and($out['message'])->toContain('content');
    expect(glob($dest . '/*'))->toBe([]); // nothing written
});

test('app.uploads.mime_map override is honoured by IonUpload::store', function () {
    bootFixtureKernel();
    config(['app.uploads.mime_map' => ['jpg' => ['text/plain']]]);
    $dest = sys_get_temp_dir() . '/ion_upl_' . bin2hex(random_bytes(4));
    mkdir($dest);
    // With the override, plain text named .jpg is now acceptable content.
    $out = IonUpload::store(makeUploaded('notes.jpg', 'plain text content'), $dest)->response();
    expect($out['error'])->toBe(0);
});

test('optional image hook post-processes a stored upload', function () {
    if (!extension_loaded('gd')) {
        $this->markTestSkipped('GD extension is not loaded.');
    }
    bootFixtureKernel();
    $dest = sys_get_temp_dir() . '/ion_upl_' . bin2hex(random_bytes(4));
    mkdir($dest);

    // a real 100x80 jpeg payload so Image::read can decode it
    $im = imagecreatetruecolor(100, 80);
    ob_start();
    imagejpeg($im);
    $jpeg = (string) ob_get_clean();
    imagedestroy($im);

    $out = IonUpload::store(makeUploaded('photo.jpg', $jpeg), $dest, [
        'image' => fn (\Ions\Media\Image $img, string $stored) => $img->cover(40, 40)->save($stored),
    ])->response();

    expect($out['error'])->toBe(0);
    $info = getimagesize($dest . '/' . $out['store_name']);
    expect($info[0])->toBe(40)->and($info[1])->toBe(40);
});
