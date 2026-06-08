<?php

use Ions\Bundles\AppKeys;

test('AppKeys issues and validates a token via the new secure Jwt', function () {
    bootFixtureKernel();
    $token = AppKeys::createJWT('user-7');
    expect($token)->toBeString();
    expect(AppKeys::validateJWT($token))->toMatchArray(['success' => true]);
});

test('AppKeys rejects a garbage token without throwing', function () {
    bootFixtureKernel();
    expect(AppKeys::validateJWT('not-a-token'))->toMatchArray(['success' => false]);
});
