<?php

use Ions\Bundles\Route;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

Route::get('/ping', function (Request $request) {
    return new Response('pong');
});

Route::get('/boom', function () {
    throw new \RuntimeException('SENSITIVE');
});
Route::get('/forbidden', function () {
    abort(403, 'no access');
});

// Chained exception fixture: DebugPage must render the getPrevious() chain.
Route::get('/boom-chained', function () {
    throw new \RuntimeException('outer failure', 0, new \LogicException('root cause detail'));
});

Route::post('/csrf-protected', fn () => new \Symfony\Component\HttpFoundation\Response('posted'));

// --- Signed-URL fixtures (Phase 8.5) ---
// Named routes guarded by the 'signed' middleware alias; exercised by
// signedRoute() + ValidateSignatureMiddleware feature tests.
Route::get('/signed/welcome', fn () => new Response('signed ok'), [], 'signed.welcome')->middleware(['signed']);
Route::get('/signed/download/{id}', fn () => new Response('download ok'), [], 'signed.download')->middleware(['signed']);

// --- Trusted-proxy fixture (Phase 10.1) ---
// Echoes what the framework believes about the connection so the trusted-proxy
// tests can assert isSecure()/getClientIp() through the full pipeline.
Route::get('/proxy-echo', function (Request $request) {
    return new Response((string) json_encode([
        'secure' => $request->isSecure(),
        'ip' => $request->getClientIp(),
    ]));
});

// --- Worker-mode isolation fixtures (Phase 8.2) ---

// Mutates the SHARED kernel response and returns null, so the pipeline falls
// back to Kernel::response() — the leak vector resetForRequest() must close.
Route::get('/leak-header', function () {
    \Ions\Foundation\Kernel::response()->headers->set('X-Leak', 'r1');
    \Ions\Foundation\Kernel::response()->setContent('leaked');
});

// Also returns null -> shared response; must NOT see /leak-header's header
// after a resetForRequest().
Route::get('/shared-response', function () {
    \Ions\Foundation\Kernel::response()->setContent('shared');
});

// --- View-return fixtures (Phase 9.2) ---
// Templates live in tests/fixtures/app/views/; the @admin namespace comes
// from config('app.twig.paths'). ViewRenderingTest points app.twig.source
// at the committed views/ dir before each request.
Route::get('/views/helper', fn () => view('@admin.users.index', ['who' => 'helper']));
Route::get('/views/dotted', \IonsFixture\Http\Controllers\PagesController::class . '::dotted');
Route::get('/views/controller-root', \IonsFixture\Http\Controllers\PagesController::class . '::index');
Route::get('/views/controller-nested', \IonsFixture\Http\Controllers\Admin\UserReports\HomeController::class . '::index');
Route::get('/views/controller-custom', \IonsFixture\Http\Controllers\CustomPathController::class . '::index');

// --- Gate fixtures (Phase 10.4) ---
// Web routes have NO auth pipeline — every request is a guest. Abilities and
// the policy are defined by IonsFixture\Providers\FixtureGateProvider.
Route::get('/gate/web-open', \IonsFixture\Http\Controllers\GatePagesController::class . '::open');
Route::get('/gate/web-members', \IonsFixture\Http\Controllers\GatePagesController::class . '::members');
Route::get('/gate/view', fn () => view('gate.check'));
Route::get('/gate/helper', fn () => new \Symfony\Component\HttpFoundation\JsonResponse([
    'open' => can('open-door'),
    'members' => can('members-area'),
]));

// Session write/read pair used by the WorkerRunner isolation test.
Route::get('/session-write', function () {
    session(['leak' => 'r1']);
    return new Response('written');
});

Route::get('/session-read', function () {
    return new Response((string) (session('leak') ?? 'clean'));
});

// --- Debug toolbar fixtures (Phase 10.6) ---
// Full HTML documents (with </body>) so DebugToolbarMiddleware injects;
// /toolbar-query runs one statement so the query counter has data.
Route::get('/toolbar-page', fn () => new Response(
    '<!DOCTYPE html><html><head><title>t</title></head><body><p>toolbar fixture</p></body></html>'
));

Route::get('/toolbar-query', function () {
    \Ions\Support\DB::connection()->select('select 1');
    return new Response('<html><body>queried</body></html>');
});
