<?php

use Ions\Filesystem\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    bootFixtureKernel();
    config(['filesystem.default' => 'mem', 'filesystem.disks.mem' => ['driver' => 'memory']]);
});

/** Create a real temp file and return its absolute path. */
function makeStorageApiTempFile(string $name, string $content): string
{
    $dir = sys_get_temp_dir() . '/ion_storage_api_' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);

    return $path;
}

// ---------------------------------------------------------------- putFile()

test('Storage::putFile() stores a local file given by string path under the directory with a random name', function () {
    $src = makeStorageApiTempFile('report.csv', 'a,b,c');

    $stored = Storage::putFile('imports', $src);

    expect($stored)->toStartWith('imports/')
        ->and($stored)->toEndWith('.csv')
        ->and(Storage::exists($stored))->toBeTrue()
        ->and(Storage::get($stored))->toBe('a,b,c');

    @unlink($src);
});

test('Storage::putFile() accepts an SplFileInfo', function () {
    $src = makeStorageApiTempFile('notes.txt', 'hello');

    $stored = Storage::putFile('docs', new SplFileInfo($src));

    expect($stored)->toStartWith('docs/')
        ->and($stored)->toEndWith('.txt')
        ->and(Storage::get($stored))->toBe('hello');

    @unlink($src);
});

test('Storage::putFile() accepts an UploadedFile and keeps the guessed extension', function () {
    $src = makeStorageApiTempFile('upl', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    $file = new UploadedFile($src, 'photo.jpg', null, null, true);

    $stored = Storage::putFile('avatars', $file);

    expect($stored)->toStartWith('avatars/')
        ->and($stored)->toEndWith('.jpg')
        ->and(Storage::exists($stored))->toBeTrue();

    @unlink($src);
});

// --------------------------------------------------------------- download()

test('Storage::download() returns a streamed attachment response with the file contents', function () {
    Storage::put('exports/data.csv', "x,y\n1,2");

    $response = Storage::download('exports/data.csv');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('data.csv');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toBe("x,y\n1,2");
});

test('Storage::download() honours a custom download name', function () {
    Storage::put('exports/2026.csv', 'rows');

    $response = Storage::download('exports/2026.csv', 'yearly-report.csv');

    expect($response->headers->get('Content-Disposition'))->toContain('yearly-report.csv');
});

// --------------------------------------------------- files() / directories()

test('Storage::files() and Storage::directories() list a directory', function () {
    Storage::put('a/one.txt', '1');
    Storage::put('a/two.txt', '2');
    Storage::put('a/b/three.txt', '3');

    expect(Storage::files('a'))->toBe(['a/one.txt', 'a/two.txt'])
        ->and(Storage::directories('a'))->toBe(['a/b'])
        ->and(Storage::files('a', recursive: true))->toBe(['a/b/three.txt', 'a/one.txt', 'a/two.txt']);
});

// ------------------------------------------------------------- copy / move

test('Storage::copy() duplicates a file and keeps the source', function () {
    Storage::put('orig.txt', 'data');

    Storage::copy('orig.txt', 'dup.txt');

    expect(Storage::exists('orig.txt'))->toBeTrue()
        ->and(Storage::get('dup.txt'))->toBe('data');
});

test('Storage::move() relocates a file and removes the source', function () {
    Storage::put('from.txt', 'data');

    Storage::move('from.txt', 'to.txt');

    expect(Storage::exists('from.txt'))->toBeFalse()
        ->and(Storage::get('to.txt'))->toBe('data');
});

// -------------------------------------------------------------------- url()

test("Storage::url() honours a disk's 'url' config key (Laravel-style alias of public_url)", function () {
    $root = sys_get_temp_dir() . '/ion_storage_urlkey_' . bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    config([
        'filesystem.default' => 'pub',
        'filesystem.disks.pub' => [
            'driver' => 'local',
            'root' => $root,
            'url' => 'https://cdn.example.test/uploads',
        ],
    ]);

    expect(Storage::url('img/logo.png'))->toBe('https://cdn.example.test/uploads/img/logo.png');

    @rmdir($root);
});

test("Storage::url() falls back to app.app_url when the disk declares no url/public_url", function () {
    $root = sys_get_temp_dir() . '/ion_storage_urlfb_' . bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    config([
        'filesystem.default' => 'plain',
        'filesystem.disks.plain' => ['driver' => 'local', 'root' => $root],
    ]);

    // fixture app.app_url is http://localhost
    expect(Storage::url('img/logo.png'))->toBe('http://localhost/img/logo.png');

    @rmdir($root);
});

// ----------------------------------------------------------- temporaryUrl()

test('Storage::temporaryUrl() throws a clear RuntimeException for drivers without temporary URL support', function () {
    Storage::put('secret.txt', 'x');

    expect(fn () => Storage::temporaryUrl('secret.txt', 3600))
        ->toThrow(RuntimeException::class, 'does not support temporary URLs');
});
