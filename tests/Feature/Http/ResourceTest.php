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

// ---------------------------------------------------------------------------
// Single resource — wrapped (default)
// ---------------------------------------------------------------------------

test('a Resource wraps payload in a single data envelope (no double-wrap)', function () {
    $resource = UserResource::make(['id' => 7, 'name' => 'Ada', 'secret' => 'x']);

    $response = $resource->toResponse(Request::create('/'));
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        // Top-level keys must be exactly ['data'] — no 'status', no extra nesting.
        ->and(array_keys($body))->toBe(['data'])
        // The value under 'data' is the resource array, NOT another ['data' => ...].
        ->and($body['data'])->toBe(['id' => 7, 'name' => 'Ada'])
        // No double envelope: $body['data']['data'] must not exist.
        ->and(isset($body['data']['data']))->toBeFalse();
});

test('a Resource reads from a stdClass model (single envelope)', function () {
    $model = (object) ['id' => 3, 'name' => 'Lin'];
    $resource = UserResource::make($model);

    $body = json_decode((string) $resource->toResponse(Request::create('/'))->getContent(), true);

    expect(array_keys($body))->toBe(['data'])
        ->and($body['data'])->toBe(['id' => 3, 'name' => 'Lin']);
});

// ---------------------------------------------------------------------------
// Single resource — withoutWrapping()
// ---------------------------------------------------------------------------

test('a Resource with withoutWrapping emits raw top-level keys (no data envelope)', function () {
    $resource = UserResource::make(['id' => 1, 'name' => 'B'])->withoutWrapping();

    $body = json_decode((string) $resource->toResponse(Request::create('/'))->getContent(), true);

    // Top-level keys are the resource's own keys — no 'data' wrapper.
    expect(array_keys($body))->toBe(['id', 'name'])
        ->and($body['id'])->toBe(1)
        ->and($body['name'])->toBe('B')
        ->and(array_key_exists('data', $body))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Collection — wrapped (default)
// ---------------------------------------------------------------------------

test('a ResourceCollection maps items through a Resource into a single data envelope', function () {
    $items = [
        ['id' => 1, 'name' => 'A'],
        ['id' => 2, 'name' => 'B'],
        ['id' => 3, 'name' => 'C'],
    ];

    $collection = UserResource::collection($items);
    $body = json_decode((string) $collection->toResponse(Request::create('/'))->getContent(), true);

    // Single envelope: top-level key is 'data', value is the list directly.
    expect(array_keys($body))->toBe(['data'])
        ->and($body['data'])->toBe([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
            ['id' => 3, 'name' => 'C'],
        ])
        // No double envelope.
        ->and(isset($body['data']['data']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Paginated collection — data + meta + links as TOP-LEVEL siblings
// ---------------------------------------------------------------------------

test('a paginated ResourceCollection emits data + meta + links as top-level siblings', function () {
    $items = [
        ['id' => 1, 'name' => 'A'],
        ['id' => 2, 'name' => 'B'],
    ];
    $paginator = new LengthAwarePaginator($items, total: 10, perPage: 2, currentPage: 1);

    $collection = new ResourceCollection($paginator, UserResource::class);
    $body = json_decode((string) $collection->toResponse(Request::create('/'))->getContent(), true);

    // 'data', 'meta', and 'links' are siblings at the TOP level — not nested.
    expect(array_key_exists('data', $body))->toBeTrue()
        ->and(array_key_exists('meta', $body))->toBeTrue()
        ->and(array_key_exists('links', $body))->toBeTrue()
        // No double-nesting: $body['data'] must be the list, not another assoc array.
        ->and($body['data'])->toHaveCount(2)
        ->and($body['data'][0])->toBe(['id' => 1, 'name' => 'A'])
        // meta and links are siblings of data, not children.
        ->and($body['meta']['current_page'])->toBe(1)
        ->and($body['meta']['per_page'])->toBe(2)
        ->and($body['meta']['total'])->toBe(10)
        ->and($body['meta']['last_page'])->toBe(5)
        ->and(array_key_exists('links', $body))->toBeTrue()
        // Confirm no extra nesting: $body['data'] has no 'meta' / 'links' / 'data' keys.
        ->and(isset($body['data']['meta']))->toBeFalse()
        ->and(isset($body['data']['links']))->toBeFalse()
        ->and(isset($body['data']['data']))->toBeFalse();
});
