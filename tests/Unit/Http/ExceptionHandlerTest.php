<?php

use Ions\Http\ExceptionHandler;
use Ions\Support\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => bootFixtureKernel());

test('renders an HttpException with its status as JSON for an api request', function () {
    $req = Request::create('/api/x');
    $res = (new ExceptionHandler())->render(new HttpException(403, 'forbidden'), $req);
    expect($res->getStatusCode())->toBe(403);
    expect(json_decode($res->getContent(), true))->toMatchArray(['status' => 'error', 'message' => 'forbidden']);
});

test('renders a generic Throwable as 500', function () {
    $res = (new ExceptionHandler())->render(new \RuntimeException('boom'), Request::create('/api/x'));
    expect($res->getStatusCode())->toBe(500);
});

test('web request gets an HTML response with the right status', function () {
    $res = (new ExceptionHandler())->render(new HttpException(404, 'nope'), Request::create('/page'));
    expect($res->headers->get('Content-Type'))->toContain('text/html')
        ->and($res->getStatusCode())->toBe(404);
});

test('does NOT leak the exception message/trace for a generic 500 when APP_DEBUG is off (api)', function () {
    // fixture .env has no APP_DEBUG → off
    $res = (new ExceptionHandler())->render(new \RuntimeException('SENSITIVE-INTERNAL-DETAIL'), Request::create('/api/x'));
    expect($res->getContent())->not->toContain('SENSITIVE-INTERNAL-DETAIL');
    expect($res->getStatusCode())->toBe(500);
});

test('an HttpException message IS shown (it is a deliberate client-facing message) even in prod', function () {
    $res = (new ExceptionHandler())->render(new HttpException(422, 'validation failed'), Request::create('/api/x'));
    expect($res->getContent())->toContain('validation failed');
});
