<?php

test('the booted app container is an Ions container and still resolves filesystem', function () {
    bootFixtureKernel();
    expect(\Ions\Foundation\Kernel::app())->toBeInstanceOf(\Ions\Container\Container::class)
        ->and(\Ions\Foundation\Kernel::app()->has('filesystem'))->toBeTrue();
});
