<?php

/**
 * FINDING 6 (root cause) regression tests.
 *
 * Path::files() and Path::filesRoot() were pure string concatenation, so a
 * caller-controlled $file / $mainFolder / $bucket containing `..` segments or
 * an absolute prefix escaped the uploads (or host) root. Containment is now
 * centralized in Path: traversal/absolute arguments throw a RuntimeException
 * (fail-closed), while legitimate nested relative subpaths pass through and the
 * rooted path is returned unchanged.
 */

use Ions\Bundles\Path;

beforeEach(fn () => bootFixtureKernel());

test('Path::files() rejects a parent-traversal argument', function () {
    Path::files('../../etc/passwd');
})->throws(RuntimeException::class);

test('Path::files() rejects a bare .. segment in a nested path', function () {
    Path::files('avatars/../../secret');
})->throws(RuntimeException::class);

test('Path::files() rejects an absolute path argument', function () {
    Path::files('/etc/passwd');
})->throws(RuntimeException::class);

test('Path::files() rejects a Windows-style absolute path argument', function () {
    Path::files('C:\\Windows\\system32');
})->throws(RuntimeException::class);

test('Path::files() rejects a UNC-style absolute path argument', function () {
    Path::files('\\\\server\\share\\x');
})->throws(RuntimeException::class);

test('Path::files() returns the rooted path for a legitimate nested subpath', function () {
    $result = Path::files('avatars/2024/x.jpg');
    expect($result)->toEndWith(
        DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars/2024/x.jpg'
    );
});

test('Path::files() rejects traversal supplied via the mainFolder argument (local disk)', function () {
    // mainFolder is only prepended on the s3 branch, but the containment check
    // must still reject a traversal value before any branch runs.
    Path::files('x.jpg', false, '../../etc');
})->throws(RuntimeException::class);

test('Path::filesRoot() rejects a parent-traversal argument', function () {
    Path::filesRoot('../../.env');
})->throws(RuntimeException::class);

test('Path::filesRoot() rejects an absolute path argument', function () {
    Path::filesRoot('/etc/shadow');
})->throws(RuntimeException::class);

test('Path::filesRoot() rejects a traversal bucket argument', function () {
    Path::filesRoot('x', false, '', '../evil');
})->throws(RuntimeException::class);

test('Path::filesRoot() returns the rooted path for a legitimate nested subpath', function () {
    $result = Path::filesRoot('reports/2024/q1.pdf');
    expect($result)->toEndWith(DIRECTORY_SEPARATOR . 'reports/2024/q1.pdf');
});
