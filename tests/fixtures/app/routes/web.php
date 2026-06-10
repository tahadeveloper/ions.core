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

// Session write/read pair used by the WorkerRunner isolation test.
Route::get('/session-write', function () {
    session(['leak' => 'r1']);
    return new Response('written');
});

Route::get('/session-read', function () {
    return new Response((string) (session('leak') ?? 'clean'));
});
