<?php

use Ions\Http\Middleware\CorsMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('preflight OPTIONS short-circuits with 204 + CORS headers and does not call next', function () {
    $reached = false;
    $mw = new CorsMiddleware(['methods' => ['GET', 'POST', 'OPTIONS']]);
    $res = $mw->handle(Request::create('/api', 'OPTIONS'), function ($r) use (&$reached) {
        $reached = true;
        return new Response('x');
    });
    expect($res->getStatusCode())->toBe(204)
        ->and($res->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($reached)->toBeFalse();
});

test('non-preflight request gets Access-Control-Allow-Origin on the downstream response', function () {
    $mw = new CorsMiddleware(['origins' => ['*']]);
    $res = $mw->handle(Request::create('/api', 'GET'), fn ($r) => new Response('ok'));
    expect($res->getContent())->toBe('ok')
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});
