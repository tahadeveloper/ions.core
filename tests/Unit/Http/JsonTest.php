<?php

use Ions\Http\Json;
use Symfony\Component\HttpFoundation\JsonResponse;

test('Json::ok wraps data in a 200 JSON response', function () {
    $r = Json::ok(['a' => 1]);
    expect($r)->toBeInstanceOf(JsonResponse::class)
        ->and($r->getStatusCode())->toBe(200)
        ->and(json_decode($r->getContent(), true))->toBe(['status' => 'success', 'data' => ['a' => 1]]);
});

test('Json::ok accepts a custom status', function () {
    expect(Json::ok(['x' => 1], 201)->getStatusCode())->toBe(201);
});

test('Json::error builds an error envelope with the given status and code', function () {
    $r = Json::error('nope', 422);
    expect($r->getStatusCode())->toBe(422)
        ->and(json_decode($r->getContent(), true))->toMatchArray(['status' => 'error', 'message' => 'nope', 'code' => 422]);
});

test('Json::error merges extra keys', function () {
    $r = Json::error('bad', 400, ['errors' => ['field' => 'required']]);
    expect(json_decode($r->getContent(), true))->toMatchArray(['status' => 'error', 'message' => 'bad', 'code' => 400, 'errors' => ['field' => 'required']]);
});
