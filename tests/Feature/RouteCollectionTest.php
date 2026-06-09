<?php

test('web routes still load and match after MRoute removal', function () {
    bootFixtureKernel();
    $response = \Ions\Foundation\Kernel::handle(\Ions\Support\Request::create('/ping'));
    expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('pong');
});
