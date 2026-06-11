<?php

declare(strict_types=1);

use Ions\Foundation\ControllerResolver;

/*
|--------------------------------------------------------------------------
| ControllerResolver collaborator (Phase 12.7 extraction)
|--------------------------------------------------------------------------
| Pure unit coverage for the controller-string resolution + framework
| namespacing extracted out of Kernel::handleRouteRequest(). This helper is
| side-effect free, so it can be exercised without booting the kernel.
*/

test("'::' controller strings split into [controller, method]", function () {
    [$controller, $method, $params] = ControllerResolver::resolve(
        ['_controller' => 'HomeController::index'],
        '',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('HomeController') // class part; no prefix when $namespace is ''
        ->and($method)->toBe('index')
        ->and($params)->toBe(['_controller' => 'HomeController::index']);
});

test("'@' controller strings split into [controller, method]", function () {
    [$controller, $method] = ControllerResolver::resolve(
        ['_controller' => 'HomeController@show', 'method' => 'fallback'],
        '',
        []
    );

    expect($controller)->toBe('HomeController')
        ->and($method)->toBe('show');
});

test('a plain controller falls back to the matcher method key', function () {
    [$controller, $method] = ControllerResolver::resolve(
        ['_controller' => 'HomeController', 'method' => 'index'],
        '',
        []
    );

    expect($controller)->toBe('HomeController')
        ->and($method)->toBe('index');
});

test('a non-needle controller gets the Controllers\\ namespace prefix', function () {
    [$controller] = ControllerResolver::resolve(
        ['_controller' => 'HomeController::index'],
        'App\\Http\\',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('App\\Http\\Controllers\\HomeController');
});

test('a needle controller (super) gets the bare namespace, no Controllers\\', function () {
    [$controller] = ControllerResolver::resolve(
        ['_controller' => 'superDashboard::index'],
        'App\\Http\\',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('App\\Http\\superDashboard');
});

test('an Api namespace gets the bare prefix even for a plain controller', function () {
    [$controller] = ControllerResolver::resolve(
        ['_controller' => 'PingController::index'],
        'App\\Http\\Api\\',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('App\\Http\\Api\\PingController');
});

test('framework Ions\\ controllers are never prefixed', function () {
    [$controller] = ControllerResolver::resolve(
        ['_controller' => 'Ions\\Http\\HealthController::up'],
        'App\\Http\\',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('Ions\\Http\\HealthController');
});

test('the legacy App\\Schedule special case is never prefixed', function () {
    [$controller] = ControllerResolver::resolve(
        ['_controller' => 'App\\Schedule', 'method' => 'boot'],
        'App\\Http\\',
        ['super', 'api', 'Api']
    );

    expect($controller)->toBe('App\\Schedule');
});

test('the legacy id => 0 placeholder is filtered out of the params', function () {
    [, , $params] = ControllerResolver::resolve(
        ['_controller' => 'HomeController::index', 'id' => 0, 'slug' => 'x'],
        '',
        []
    );

    expect($params)->toBe(['_controller' => 'HomeController::index', 'slug' => 'x']);
});

test('a non-zero id is preserved in the params', function () {
    [, , $params] = ControllerResolver::resolve(
        ['_controller' => 'HomeController::index', 'id' => 7],
        '',
        []
    );

    expect($params)->toBe(['_controller' => 'HomeController::index', 'id' => 7]);
});
