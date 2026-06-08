<?php

use Ions\Security\UploadValidator;

test('rejects php and other executable extensions', function () {
    $v = new UploadValidator(['jpg', 'png', 'pdf']);
    expect($v->isAllowed('shell.php'))->toBeFalse()
        ->and($v->isAllowed('a.phtml'))->toBeFalse()
        ->and($v->isAllowed('a.PHP5'))->toBeFalse()
        ->and($v->isAllowed('archive.phar'))->toBeFalse();
});

test('accepts allow-listed extensions case-insensitively', function () {
    $v = new UploadValidator(['jpg', 'png']);
    expect($v->isAllowed('photo.JPG'))->toBeTrue()
        ->and($v->isAllowed('image.png'))->toBeTrue();
});

test('rejects extensions not on the allow-list even if not dangerous', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->isAllowed('doc.txt'))->toBeFalse();
});

test('rejects files with no extension', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->isAllowed('noext'))->toBeFalse();
});

test('safeExtension strips path and lowercases', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->safeExtension('../../x.JPG'))->toBe('jpg');
});

test('rejects newly-denied extensions phtm and inc', function () {
    $v = new UploadValidator(['jpg', 'phtm', 'inc']); // even if someone allow-lists them, DENY wins
    expect($v->isAllowed('evil.phtm'))->toBeFalse()
        ->and($v->isAllowed('config.inc'))->toBeFalse()
        ->and($v->isAllowed('shell.php9'))->toBeFalse()
        ->and($v->isAllowed('shell.php10'))->toBeFalse();
});
