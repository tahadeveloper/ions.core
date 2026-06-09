<?php

use Ions\Filesystem\FilesystemManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

beforeEach(fn () => bootFixtureKernel());

test('resolves the default disk and reads/writes', function () {
    config(['filesystem.default' => 'mem', 'filesystem.disks.mem' => ['driver' => 'memory']]);

    $mgr = new FilesystemManager();
    $mgr->disk()->write('a.txt', 'hello');

    expect($mgr->disk())->toBeInstanceOf(Filesystem::class)
        ->and($mgr->disk()->read('a.txt'))->toBe('hello');
});

test('caches the resolved disk per name', function () {
    config(['filesystem.default' => 'mem', 'filesystem.disks.mem' => ['driver' => 'memory']]);

    $mgr = new FilesystemManager();

    expect($mgr->disk('mem'))->toBe($mgr->disk('mem'))
        ->and($mgr->disk())->toBe($mgr->disk('mem'));
});

test('resolves a named local disk', function () {
    $root = sys_get_temp_dir() . '/ion_fsm_' . bin2hex(random_bytes(4));
    mkdir($root, 0755, true);

    config(['filesystem.disks.tmp' => ['driver' => 'local', 'root' => $root]]);

    $mgr = new FilesystemManager();
    $disk = $mgr->disk('tmp');

    $disk->write('hello.txt', 'world');
    expect($disk->read('hello.txt'))->toBe('world')
        ->and(file_exists($root . '/hello.txt'))->toBeTrue();

    $disk->delete('hello.txt');
    expect($disk->fileExists('hello.txt'))->toBeFalse();

    @rmdir($root);
});

test('unknown driver throws InvalidArgumentException', function () {
    config(['filesystem.disks.bogus' => ['driver' => 'nope']]);

    $mgr = new FilesystemManager();
    $mgr->disk('bogus');
})->throws(InvalidArgumentException::class, 'Unsupported filesystem driver [nope]');

test('missing disk config throws InvalidArgumentException', function () {
    $mgr = new FilesystemManager();
    $mgr->disk('does-not-exist');
})->throws(InvalidArgumentException::class);

test('custom driver via extend()', function () {
    $mgr = new FilesystemManager();
    $mgr->extend('fake', function (array $config): FilesystemAdapter {
        return new InMemoryFilesystemAdapter();
    });

    config(['filesystem.disks.fakedisk' => ['driver' => 'fake']]);

    $disk = $mgr->disk('fakedisk');
    $disk->write('x.txt', 'custom');
    expect($disk->read('x.txt'))->toBe('custom');
});

test('ships built-in factories for all drivers', function () {
    $mgr = new FilesystemManager();

    expect($mgr->getDrivers())->toContain('local')
        ->toContain('memory')
        ->toContain('s3')
        ->toContain('ftp')
        ->toContain('sftp');
});
