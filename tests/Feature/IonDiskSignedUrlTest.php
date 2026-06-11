<?php

/**
 * FINDING 7 regression tests — getSignedUrl() must return a *presigned*
 * (time-limited, signed) URL, not a permanent unsigned public URL; and the
 * per-call bucket/basePath override must not bleed into static state across
 * calls (worker-mode cross-request safety).
 */

use Illuminate\Support\Collection;
use Ions\Bundles\IonDisk;

beforeEach(function () {
    bootFixtureKernel();
    // Give the s3 disk usable (dummy) credentials — presigning is a local HMAC,
    // no network call is made.
    config([
        'filesystem.disks.s3.region' => 'us-east-1',
        'filesystem.disks.s3.version' => 'latest',
        'filesystem.disks.s3.key' => 'AKIAEXAMPLE',
        'filesystem.disks.s3.secret' => 'secret123',
        'filesystem.disks.s3.bucket' => 'primary-bucket',
    ]);
    // Re-seed IonDisk's static config view (bucket/basePath/type) from the
    // now-configured s3 disk. init() reads config at call time; without this,
    // self::$bucket retains whatever it held at class-load time (null).
    IonDisk::init();
    IonDisk::disk('s3');
});

afterEach(fn () => IonDisk::disk('local'));

test('getSignedUrl() returns a presigned (signed, time-limited) URL', function () {
    $url = IonDisk::getSignedUrl('path/to/file.txt', 600);

    expect($url)->toContain('X-Amz-Signature')
        ->and($url)->toContain('X-Amz-Expires=600')
        ->and($url)->toContain('path/to/file.txt');
});

test('getSignedUrl() per-call bucket override does not bleed into static state', function () {
    // First call overrides the bucket for THIS call only.
    $opts = new Collection(['bucket' => 'override-bucket']);
    $url1 = IonDisk::getSignedUrl('a.txt', 600, $opts);
    expect($url1)->toContain('override-bucket');

    // A subsequent call with NO override must fall back to the configured
    // primary bucket — the override must not have leaked into self::$bucket.
    $url2 = IonDisk::getSignedUrl('b.txt', 600);
    expect($url2)->toContain('primary-bucket')
        ->and($url2)->not->toContain('override-bucket');
});
