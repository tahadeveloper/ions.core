<?php

use Ions\Foundation\Kernel;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Support\Request;

// fake controller capturing lifecycle order
class FakeDispatchController
{
    public static array $calls = [];

    public function _initState(Request $r): void
    {
        self::$calls[] = '_initState';
    }

    public function _loadInit(Request $r): void
    {
        self::$calls[] = '_loadInit';
    }

    public function _loadedState(Request $r): void
    {
        self::$calls[] = '_loadedState';
    }

    public function show(Request $r): void
    {
        self::$calls[] = 'show';
        Kernel::response()->setContent('shown');
    }

    public function _endState(Request $r): void
    {
        self::$calls[] = '_endState';
    }
}

beforeEach(function () {
    bootFixtureKernel();
    FakeDispatchController::$calls = [];
});

test('dispatches a container-resolved controller, runs hooks in order, returns the Response', function () {
    $dispatcher = new ControllerDispatcher(Kernel::app(), FakeDispatchController::class, 'show');
    $response = $dispatcher(Request::create('/'));
    expect(FakeDispatchController::$calls)->toBe(['_initState', '_loadInit', '_loadedState', 'show', '_endState'])
        ->and($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\Response::class)
        ->and($response->getContent())->toBe('shown');
});

test('a controller without optional hooks still runs its action', function () {
    $bare = new class () {
        public function ping(Request $r): void
        {
            Kernel::response()->setContent('pong');
        }
    };
    // resolve a concrete class name; use the anonymous class's name
    Kernel::app()->bind('bare-ctrl', fn () => $bare);
    $dispatcher = new ControllerDispatcher(Kernel::app(), 'bare-ctrl', 'ping');
    $response = $dispatcher(Request::create('/'));
    expect($response->getContent())->toBe('pong');
});
