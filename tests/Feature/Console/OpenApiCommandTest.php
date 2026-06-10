<?php

use Ions\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    \Ions\Foundation\Kernel::resetForTesting();
});

test('openapi:generate is registered on the console kernel', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    expect($app->has('openapi:generate'))->toBeTrue();
});

test('openapi:generate emits a valid OpenAPI 3 spec for the fixture routes', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    $tester = new CommandTester($app->find('openapi:generate'));
    $tester->execute(['--stdout' => true]);

    expect($tester->getStatusCode())->toBe(0);

    $spec = json_decode($tester->getDisplay(), true);

    expect($spec)->toBeArray()
        ->and($spec['openapi'])->toStartWith('3.')
        ->and($spec['info'])->toHaveKeys(['title', 'version'])
        ->and($spec['components']['securitySchemes']['bearerAuth'])->toBe([
            'type' => 'http',
            'scheme' => 'bearer',
        ]);

    // Known fixture paths are present with the right methods.
    expect($spec['paths'])->toHaveKey('/api/auth/login')
        ->and($spec['paths']['/api/auth/login'])->toHaveKey('post')
        ->and($spec['paths'])->toHaveKey('/ping')
        ->and($spec['paths']['/ping'])->toHaveKey('get');

    // A public auth endpoint carries NO bearer security.
    expect($spec['paths']['/api/auth/login']['post'])->not->toHaveKey('security');

    // A protected api route carries the bearer security requirement.
    expect($spec['paths']['/api/secret']['get']['security'])->toBe([['bearerAuth' => []]]);
});

test('openapi:generate writes the spec to the --output file', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    $output = sys_get_temp_dir() . '/ions-openapi-' . uniqid() . '.json';

    $tester = new CommandTester($app->find('openapi:generate'));
    $tester->execute(['--output' => $output]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(file_exists($output))->toBeTrue();

    $spec = json_decode((string) file_get_contents($output), true);
    expect($spec['openapi'])->toStartWith('3.');

    @unlink($output);
});

test('openapi:generate records path parameters from placeholders', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    $tester = new CommandTester($app->find('openapi:generate'));
    $tester->execute(['--stdout' => true]);

    $spec = json_decode($tester->getDisplay(), true);

    // The fixture exposes /api/items/{id}
    expect($spec['paths'])->toHaveKey('/api/items/{id}');
    $params = $spec['paths']['/api/items/{id}']['get']['parameters'];
    expect($params)->toBeArray()
        ->and($params[0]['name'])->toBe('id')
        ->and($params[0]['in'])->toBe('path')
        ->and($params[0]['required'])->toBeTrue();
});
