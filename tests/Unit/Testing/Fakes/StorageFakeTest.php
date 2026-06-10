<?php

declare(strict_types=1);

use Ions\Filesystem\Storage;
use Ions\Testing\Fakes\StorageFake;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(fn () => bootFixtureKernel());

test('Storage::fake() swaps the default disk for a fresh in-memory disk', function () {
    $fake = Storage::fake();

    expect($fake)->toBeInstanceOf(StorageFake::class)
        // normal Storage calls now resolve the faked disk
        ->and(Storage::disk())->toBe($fake->disk());
});

test('files written through the Storage facade land in the fake, not on the real disk', function () {
    $path = 'fake-' . uniqid('', true) . '.txt';

    Storage::fake();
    Storage::put($path, 'in-memory');

    expect(Storage::get($path))->toBe('in-memory')
        // the configured local disk (sys temp dir) was never touched
        ->and(is_file(sys_get_temp_dir() . '/' . $path))->toBeFalse();
});

test('assertStored / assertExists pass for written paths and fail otherwise', function () {
    $fake = Storage::fake();

    Storage::put('a/b.txt', 'x');

    $fake->assertStored('a/b.txt');
    $fake->assertExists('a/b.txt');

    expect(fn () => $fake->assertStored('missing.txt'))
        ->toThrow(AssertionFailedError::class);
});

test('assertMissing passes for absent paths and fails for written ones', function () {
    $fake = Storage::fake();

    $fake->assertMissing('nope.txt');

    Storage::put('yep.txt', 'x');

    expect(fn () => $fake->assertMissing('yep.txt'))
        ->toThrow(AssertionFailedError::class);
});

test('a named disk can be faked, forcing memory even for non-memory drivers', function () {
    // 's3' is configured with the s3 driver in the fixture app
    $fake = Storage::fake('s3');

    Storage::disk('s3')->write('remote.txt', 'never-leaves-the-test');

    $fake->assertStored('remote.txt');
    expect(Storage::disk('s3'))->toBe($fake->disk())
        // other disks are untouched by the named fake
        ->and(Storage::disk('local'))->not->toBe($fake->disk());
});

test('the fake is scoped to the current boot: a reboot restores the real disk', function () {
    $fake = Storage::fake();
    Storage::put('scoped.txt', 'x');
    $fake->assertStored('scoped.txt');

    bootFixtureKernel();

    expect(Storage::disk())->not->toBe($fake->disk())
        ->and(Storage::exists('scoped.txt'))->toBeFalse();
});
