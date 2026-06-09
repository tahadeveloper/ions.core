<?php

use Symfony\Component\HttpFoundation\Response;

class RespTestController extends \Ions\Foundation\ApiController
{
    public function viaDisplay(): Response
    {
        return $this->display('{"a":1}');
    }
    public function viaNotFound(): Response
    {
        return $this->notFoundResponse('missing');
    }
    public function viaUnauthorized(): Response
    {
        return $this->unauthorizedResponse('nope');
    }
}

beforeEach(fn () => bootFixtureKernel());

test('display() returns a Response with the JSON body and application/json (no exit)', function () {
    $res = (new RespTestController())->viaDisplay();
    expect($res)->toBeInstanceOf(Response::class)
        ->and($res->getContent())->toBe('{"a":1}')
        ->and($res->headers->get('Content-Type'))->toContain('application/json');
});

test('notFoundResponse() returns a 404 Response preserving the existing envelope', function () {
    $res = (new RespTestController())->viaNotFound();
    expect($res->getStatusCode())->toBe(404);
    $body = json_decode($res->getContent(), true);
    expect($body)->toMatchArray(['status_code' => 404, 'success' => false, 'error' => 'missing']);
});

test('unauthorizedResponse() returns a 401 Response', function () {
    $res = (new RespTestController())->viaUnauthorized();
    expect($res->getStatusCode())->toBe(401);
    expect(json_decode($res->getContent(), true))->toMatchArray(['status_code' => 401, 'success' => false]);
});
