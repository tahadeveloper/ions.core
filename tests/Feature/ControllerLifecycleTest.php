<?php

declare(strict_types=1);

use IonsFixture\Http\Controllers\Lifecycle\AfterActionController;
use IonsFixture\Http\Controllers\Lifecycle\BadMiddlewareController;
use IonsFixture\Http\Controllers\Lifecycle\BareInstanceMiddlewareController;
use IonsFixture\Http\Controllers\Lifecycle\ConstructorController;
use IonsFixture\Http\Controllers\Lifecycle\HookOrderController;
use IonsFixture\Http\Controllers\Lifecycle\InjectionController;
use IonsFixture\Http\Controllers\Lifecycle\LegacyOnlyController;
use IonsFixture\Http\Controllers\Lifecycle\ProtectedHooksController;
use IonsFixture\Http\Controllers\Lifecycle\ShortCircuitController;
use IonsFixture\Lifecycle\Recorder;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Http\Responsable;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Controller lifecycle + DI end-to-end through Kernel::handle() (Phase 9.3)
|--------------------------------------------------------------------------
| Fixture controllers: tests/fixtures/app/http-controllers/Lifecycle/
| Shared recorder:     IonsFixture\Lifecycle\Recorder
| Provider binding:    GreeterContract -> SimpleGreeter (FixtureAutoProvider)
|
| Documented hook order:
|   __construct → _initState → _loadInit → _loadedState → boot
|   → [controller middleware] → beforeAction → action → afterAction
|   → [/controller middleware] → _endState
*/

beforeEach(function () {
    bootFixtureKernel();
    Recorder::reset();

    Route::get('/lifecycle/order', HookOrderController::class . '::show');
    Route::get('/lifecycle/guard', ShortCircuitController::class . '::show');
    Route::get('/lifecycle/after', AfterActionController::class . '::show');
    Route::get('/lifecycle/ctor', ConstructorController::class . '::show');
    Route::get('/lifecycle/legacy', LegacyOnlyController::class . '::show');
    Route::get('/lifecycle/bad-mw', BadMiddlewareController::class . '::show');
    Route::get('/lifecycle/bare-mw', BareInstanceMiddlewareController::class . '::show');
    Route::get('/lifecycle/protected-hooks', ProtectedHooksController::class . '::show');
    Route::get('/lifecycle/inject/{id}/{slug}', InjectionController::class . '::show');
    Route::get('/lifecycle/inject-default/{id}', InjectionController::class . '::show');

    // Closure fixtures: Responsable returns (normalizer unification) and
    // method injection of route placeholders into closures.
    Route::get('/lifecycle/closure-responsable', fn () => new class () implements Responsable {
        public function toResponse(Request $request): Response
        {
            return new Response('closure-responsable', 202);
        }
    });
    Route::get('/lifecycle/closure-inject/{id}', fn (Request $r, int $id) => new Response('closure:' . get_debug_type($id) . ':' . $id));
});

// ---------------------------------------------------------------------------
// Hook order + boot() injection + middleware() placement
// ---------------------------------------------------------------------------

test('the full lifecycle fires in the documented order with boot() method-injected', function () {
    $response = Kernel::handle(Request::create('/lifecycle/order'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('hook-order')
        ->and(Recorder::$events)->toBe([
            '__construct',
            '_initState',
            '_loadInit',
            '_loadedState',
            'boot:stamped',
            'middleware:before',
            'beforeAction',
            'action',
            'afterAction',
            'middleware:after',
            '_endState',
        ])
        ->and($response->headers->get('X-Controller-Mw'))->toBe('ran')
        ->and($response->headers->get('X-After'))->toBe('decorated');
});

test('a legacy-only controller keeps the exact pre-9.3 sequence and untyped-action Request', function () {
    $response = Kernel::handle(Request::create('/lifecycle/legacy'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('legacy')
        ->and(Recorder::$events)->toBe([
            '_initState',
            '_loadInit',
            '_loadedState',
            'action:' . Request::class,
            '_endState',
        ]);
});

// ---------------------------------------------------------------------------
// Constructor + action injection
// ---------------------------------------------------------------------------

test('constructor dependencies resolve from the container on a BaseController subclass', function () {
    $response = Kernel::handle(Request::create('/lifecycle/ctor'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ctor:hello');
});

test('action injection resolves request, services, int-cast placeholders and defaults end-to-end', function () {
    $response = Kernel::handle(Request::create('/lifecycle/inject/42/news'));
    expect($response->getContent())->toBe('hello|stamped|int|42|news');

    $response = Kernel::handle(Request::create('/lifecycle/inject-default/7'));
    expect($response->getContent())->toBe('hello|stamped|int|7|unset');
});

// ---------------------------------------------------------------------------
// beforeAction / afterAction
// ---------------------------------------------------------------------------

test('beforeAction returning a Response short-circuits: 403, action and afterAction skipped, _endState runs', function () {
    $response = Kernel::handle(Request::create('/lifecycle/guard?deny=1'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toBe('denied')
        ->and(Recorder::$events)->toBe(['beforeAction', '_endState']);
});

test('beforeAction returning null lets the action run normally', function () {
    $response = Kernel::handle(Request::create('/lifecycle/guard'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('allowed')
        ->and(Recorder::$events)->toBe(['beforeAction', 'action', 'afterAction', '_endState']);
});

test('afterAction can decorate the normalized response (null return keeps it)', function () {
    $response = Kernel::handle(Request::create('/lifecycle/after'));

    expect($response->getContent())->toBe('original')
        ->and($response->headers->get('X-Decorated'))->toBe('yes');
});

test('afterAction returning a Response replaces the action response', function () {
    $response = Kernel::handle(Request::create('/lifecycle/after?replace=1'));

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('replaced');
});

// ---------------------------------------------------------------------------
// middleware() — fail-closed per the 4.1 per-route policy
// ---------------------------------------------------------------------------

test('an unresolvable middleware() entry fails closed with a 500 and never serves the action', function () {
    $response = Kernel::handle(Request::create('/lifecycle/bad-mw'));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->not->toContain('must-not-run');
});

test('middleware() returning a bare MiddlewareInterface instance genuinely runs (not silently dropped)', function () {
    $response = Kernel::handle(Request::create('/lifecycle/bare-mw'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('bare-instance')
        ->and($response->headers->get('X-Controller-Mw'))->toBe('ran')
        ->and(Recorder::$events)->toBe(['middleware:before', 'middleware:after']);
});

// ---------------------------------------------------------------------------
// Hook detection is PUBLIC-only — non-public name collisions are not hooks
// ---------------------------------------------------------------------------

test('a protected boot() helper is not treated as a hook: dispatch succeeds, hook skipped, private middleware() skipped', function () {
    $response = Kernel::handle(Request::create('/lifecycle/protected-hooks'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('protected-hooks:helper')
        // Recorded once — by the action calling its own helper, never by the
        // dispatcher invoking it as the boot() lifecycle hook.
        ->and(Recorder::$events)->toBe(['protected-boot-helper']);
});

// ---------------------------------------------------------------------------
// Short-circuit × middleware() sub-pipeline ordering (deny path)
// ---------------------------------------------------------------------------

test('a beforeAction short-circuit unwinds through the controller middleware() wrap before _endState', function () {
    $response = Kernel::handle(Request::create('/lifecycle/order?deny=1'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toBe('denied')
        ->and(Recorder::$events)->toBe([
            '__construct',
            '_initState',
            '_loadInit',
            '_loadedState',
            'boot:stamped',
            'middleware:before',
            'beforeAction',
            'middleware:after',
            '_endState',
        ])
        // The early 403 still passes back through the middleware unwind.
        ->and($response->headers->get('X-Controller-Mw'))->toBe('ran');
});

// ---------------------------------------------------------------------------
// Normalizer unification (closure routes) + closure injection
// ---------------------------------------------------------------------------

test('a closure route returning a Responsable is now converted (normalizer unification)', function () {
    $response = Kernel::handle(Request::create('/lifecycle/closure-responsable'));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('closure-responsable');
});

test('closure routes receive method-injected route placeholders', function () {
    $response = Kernel::handle(Request::create('/lifecycle/closure-inject/9'));

    expect($response->getContent())->toBe('closure:int:9');
});
