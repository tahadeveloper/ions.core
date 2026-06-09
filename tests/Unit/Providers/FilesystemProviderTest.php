<?php

use Ions\Container\Container;
use Ions\Providers\FilesystemProvider;

test('registers filesystem and files singletons', function () {
    $c = new Container();
    (new FilesystemProvider($c))->register();
    expect($c->get('filesystem'))->toBeInstanceOf(\Illuminate\Filesystem\Filesystem::class)
        ->and($c->get('files'))->toBe($c->get('files')); // singleton identity
});

test('does not re-bind if already bound', function () {
    $c = new Container();
    $existing = new \Illuminate\Filesystem\Filesystem();
    $c->instance('filesystem', $existing);
    (new FilesystemProvider($c))->register();
    expect($c->get('filesystem'))->toBe($existing); // preserved, not overwritten
});

test('binds the FilesystemManager and default disk', function () {
    bootFixtureKernel();
    $c = \Ions\Foundation\Kernel::app();

    expect($c->get('filesystem.manager'))->toBeInstanceOf(\Ions\Filesystem\FilesystemManager::class)
        ->and($c->get('filesystem.manager'))->toBe($c->get('filesystem.manager')) // singleton
        ->and($c->get('filesystem.disk'))->toBeInstanceOf(\League\Flysystem\Filesystem::class);
});
