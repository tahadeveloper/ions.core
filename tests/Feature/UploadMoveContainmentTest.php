<?php

/**
 * FINDING 2 regression tests — MOVE / COPY containment (both ends) plus
 * IonUpload::moveLocal()/moveUrl().
 *
 * IonDisk::move()/copy() resolve source AND dest through Path::files()/
 * filesRoot(); a `../../`-escaping end must be rejected. moveUrl()'s file name
 * comes from an attacker URL's last segment and must be basename-reduced.
 * Legitimate moves keep working.
 */

use Ions\Bundles\IonDisk;
use Ions\Bundles\IonUpload;

beforeEach(fn () => bootFixtureKernel());

test('IonDisk::copy() rejects a ../../ escaping source via Path::files containment', function () {
    IonDisk::disk('local');
    // No options → both ends route through Path::files(); a traversal source
    // is rejected by the centralized Path containment (Finding 6).
    IonDisk::copy('../../.env', 'dest.txt');
})->throws(RuntimeException::class);

test('IonDisk::copy() rejects a ../../ escaping destination', function () {
    IonDisk::disk('local');
    IonDisk::copy('a.txt', '../../escape.txt');
})->throws(RuntimeException::class);

test('IonDisk::move() rejects a ../../ escaping source', function () {
    IonDisk::disk('local');
    IonDisk::move('../../secret', 'd.txt');
})->throws(RuntimeException::class);

test('IonDisk::move() rejects a ../../ escaping destination', function () {
    IonDisk::disk('local');
    IonDisk::move('a.txt', '../../escape.txt');
})->throws(RuntimeException::class);

test('IonUpload::moveLocal() rejects a traversal source/dest via Path containment', function () {
    IonUpload::moveLocal('../../etc', 'dest', 'passwd');
})->throws(RuntimeException::class);

test('IonUpload::moveLocal() accepts a legitimate nested relative move (no traversal)', function () {
    // A legitimate relative source/dest must NOT be rejected by the centralized
    // Path containment — both ends go through Path::files() and resolve to the
    // rooted uploads path without throwing. (The actual byte move depends on
    // disk wiring; the security-relevant claim is that containment lets it
    // through.)
    $out = IonUpload::moveLocal('avatars/2024', 'avatars/archive', 'pic.jpg')->response();

    // moveLocal returns false when the source isn't present on the disk; the
    // point is it did NOT throw a containment RuntimeException.
    expect($out)->toBeFalse();
});

test('IonUpload::moveUrl() basenames a traversal filename from the URL last segment', function () {
    // A malicious URL whose last segment is a traversal payload must not be
    // passed raw into Path::files() (which would throw) — it is basenamed first
    // so the lookup stays a single segment and simply finds nothing.
    $out = IonUpload::moveUrl('http://evil/dump/..%2f..%2f.env/../../.env', 'dest')->response();

    // No exception, and nothing matched → null output.
    expect($out)->toBeNull();
});
