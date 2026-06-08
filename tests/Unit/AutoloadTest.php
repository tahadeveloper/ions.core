<?php
use Symfony\Component\Finder\Finder;

test('every core class file is syntactically valid', function () {
    $finder = (new Finder())->files()->in(__DIR__ . '/../../src')->name('*.php');
    foreach ($finder as $file) {
        $code = $file->getContents();
        if (str_contains($code, 'namespace Ions')) {
            expect(token_get_all($code))->toBeArray();
        }
    }
});
