<?php

declare(strict_types=1);

use Ions\Http\Ui\EditorLink;

beforeEach(function () {
    bootFixtureKernel();
});

afterEach(function () {
    config(['app.debug.editor' => null]);
});

test('vscode key maps to the vscode://file URI', function () {
    config(['app.debug.editor' => 'vscode']);

    expect(EditorLink::for('/var/www/app/Foo.php', 42))
        ->toBe('vscode://file//var/www/app/Foo.php:42');
});

test('phpstorm key maps to the phpstorm://open URI', function () {
    config(['app.debug.editor' => 'phpstorm']);

    expect(EditorLink::for('/var/www/app/Foo.php', 42))
        ->toBe('phpstorm://open?file=/var/www/app/Foo.php&line=42');
});

test('sublime key maps to the subl://open URI', function () {
    config(['app.debug.editor' => 'sublime']);

    expect(EditorLink::for('/var/www/app/Foo.php', 7))
        ->toBe('subl://open?url=file:///var/www/app/Foo.php&line=7');
});

test('textmate key maps to the txmt://open URI', function () {
    config(['app.debug.editor' => 'textmate']);

    expect(EditorLink::for('/var/www/app/Foo.php', 7))
        ->toBe('txmt://open?url=file:///var/www/app/Foo.php&line=7');
});

test('idea key maps to the idea://open URI', function () {
    config(['app.debug.editor' => 'idea']);

    expect(EditorLink::for('/var/www/app/Foo.php', 9))
        ->toBe('idea://open?file=/var/www/app/Foo.php&line=9');
});

test('an unset editor returns null', function () {
    config(['app.debug.editor' => null]);

    expect(EditorLink::for('/var/www/app/Foo.php', 1))->toBeNull();
});

test('a false editor returns null', function () {
    config(['app.debug.editor' => false]);

    expect(EditorLink::for('/var/www/app/Foo.php', 1))->toBeNull();
});

test('an empty-string editor returns null', function () {
    config(['app.debug.editor' => '']);

    expect(EditorLink::for('/var/www/app/Foo.php', 1))->toBeNull();
});

test('a custom format string with placeholders is honoured', function () {
    config(['app.debug.editor' => 'editor://open/{file}/{line}']);

    expect(EditorLink::for('/var/www/app/Foo.php', 12))
        ->toBe('editor://open//var/www/app/Foo.php/12');
});

test('a path containing a space is URL-encoded', function () {
    config(['app.debug.editor' => 'vscode']);

    $link = EditorLink::for('/var/www/my app/Foo.php', 3);

    expect($link)->not->toContain(' ');
    expect($link)->toContain('%20');
});
