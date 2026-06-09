<?php

use Ions\Foundation\Kernel;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Http\Responsable;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class ReturningController
{
    public function show(Request $r): Response
    {
        return new Response('returned', 201);
    }
}
class VoidController
{
    public function show(Request $r): void
    {
        Kernel::response()->setContent('void-written');
    }
}
class ResponsableController
{
    public function show(Request $r): Responsable
    {
        return new class () implements Responsable {
            public function toResponse(Request $request): Response
            {
                return new Response('via-responsable', 202);
            }
        };
    }
}

beforeEach(fn () => bootFixtureKernel());

test('dispatcher uses a Response returned by the action', function () {
    $res = (new ControllerDispatcher(Kernel::app(), ReturningController::class, 'show'))(Request::create('/'));
    expect($res->getStatusCode())->toBe(201)->and($res->getContent())->toBe('returned');
});

test('dispatcher resolves a Responsable returned by the action', function () {
    $res = (new ControllerDispatcher(Kernel::app(), ResponsableController::class, 'show'))(Request::create('/'));
    expect($res->getStatusCode())->toBe(202)->and($res->getContent())->toBe('via-responsable');
});

test('dispatcher falls back to the shared response for void actions (BC)', function () {
    $res = (new ControllerDispatcher(Kernel::app(), VoidController::class, 'show'))(Request::create('/'));
    expect($res->getContent())->toBe('void-written');
});
