<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Ions\Http\Resource;
use Ions\Http\ResourceCollection;
use Ions\Support\Request;

class UserResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->get('id'),
            'name' => $this->get('name'),
        ];
    }
}

test('a Resource shapes a model into a wrapped data payload', function () {
    $resource = UserResource::make(['id' => 7, 'name' => 'Ada', 'secret' => 'x']);

    $response = $resource->toResponse(Request::create('/'));
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('success')
        // Json::ok envelope -> data ; Resource wrap -> data
        ->and($payload['data']['data'])->toBe(['id' => 7, 'name' => 'Ada']);
});

test('a Resource can disable wrapping', function () {
    $resource = (UserResource::make(['id' => 1, 'name' => 'B']))->withoutWrapping();

    $payload = json_decode((string) $resource->toResponse(Request::create('/'))->getContent(), true);

    expect($payload['data'])->toBe(['id' => 1, 'name' => 'B']);
});

test('a Resource reads from a stdClass model', function () {
    $model = (object) ['id' => 3, 'name' => 'Lin'];
    $resource = UserResource::make($model);

    $payload = json_decode((string) $resource->toResponse(Request::create('/'))->getContent(), true);

    expect($payload['data']['data'])->toBe(['id' => 3, 'name' => 'Lin']);
});

test('a ResourceCollection maps N items through a Resource class', function () {
    $items = [
        ['id' => 1, 'name' => 'A'],
        ['id' => 2, 'name' => 'B'],
        ['id' => 3, 'name' => 'C'],
    ];

    $collection = UserResource::collection($items);
    $payload = json_decode((string) $collection->toResponse(Request::create('/'))->getContent(), true);

    expect($payload['data']['data'])->toBe([
        ['id' => 1, 'name' => 'A'],
        ['id' => 2, 'name' => 'B'],
        ['id' => 3, 'name' => 'C'],
    ]);
});

test('a ResourceCollection over a paginator emits data + meta', function () {
    $items = [
        ['id' => 1, 'name' => 'A'],
        ['id' => 2, 'name' => 'B'],
    ];
    $paginator = new LengthAwarePaginator($items, total: 10, perPage: 2, currentPage: 1);

    $collection = new ResourceCollection($paginator, UserResource::class);
    $payload = json_decode((string) $collection->toResponse(Request::create('/'))->getContent(), true);

    expect($payload['data']['data'])->toHaveCount(2)
        ->and($payload['data']['meta']['current_page'])->toBe(1)
        ->and($payload['data']['meta']['per_page'])->toBe(2)
        ->and($payload['data']['meta']['total'])->toBe(10)
        ->and($payload['data']['meta']['last_page'])->toBe(5)
        ->and($payload['data'])->toHaveKey('links');
});
