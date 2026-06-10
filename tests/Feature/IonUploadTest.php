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
