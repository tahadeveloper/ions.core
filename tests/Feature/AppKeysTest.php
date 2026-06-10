<?php

use Ions\Bundles\AppKeys;
use Ions\Foundation\Kernel;
use Ions\Support\Request;

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

test('a user-bound AppKeys token resolves to that user (not the app id) through AuthMiddleware', function () {
    bootFixtureKernel();

    // Issue with the user id as the subject (the encouraged, user-bound path).
    $token = AppKeys::createJWT('user-7');
    expect($token)->toBeString();

    $request = Request::create('/api/secret');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('user-7');
});
