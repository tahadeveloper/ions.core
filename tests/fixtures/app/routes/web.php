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

Route::post('/csrf-protected', fn () => new \Symfony\Component\HttpFoundation\Response('posted'));
