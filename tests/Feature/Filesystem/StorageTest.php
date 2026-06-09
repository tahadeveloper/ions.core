<?php

use Ions\Filesystem\FilesystemManager;
use Ions\Filesystem\Storage;
use League\Flysystem\Filesystem;

beforeEach(function () {
    bootFixtureKernel();
    config(['filesystem.default' => 'mem', 'filesystem.disks.mem' => ['driver' => 'memory']]);
});

test('Storage::manager() returns the container-bound FilesystemManager', function () {
    expect(Storage::manager())->toBeInstanceOf(FilesystemManager::class)
        ->and(Storage::manager())->toBe(Storage::manager()); // singleton identity
});

test('Storage::disk() returns a Flysystem disk', function () {
    expect(Storage::disk())->toBeInstanceOf(Filesystem::class)
        ->and(Storage::disk('mem'))->toBe(Storage::disk()); // default resolves to mem
});

test('Storage put/get/exists/delete pass through to the default disk', function () {
    Storage::put('note.txt', 'remember');

    expect(Storage::exists('note.txt'))->toBeTrue()
        ->and(Storage::get('note.txt'))->toBe('remember');

    Storage::delete('note.txt');
    expect(Storage::exists('note.txt'))->toBeFalse();
});

test('Storage::url() delegates to the disk public url', function () {
    $root = sys_get_temp_dir() . '/ion_storage_url_' . bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    config([
        'filesystem.default' => 'pub',
        'filesystem.disks.pub' => [
            'driver' => 'local',
            'root' => $root,
            'public_url' => 'https://cdn.example.test',
        ],
    ]);
    Storage::manager()->forgetDisk();

    expect(Storage::url('img/logo.png'))->toBe('https://cdn.example.test/img/logo.png');

    @rmdir($root);
});
