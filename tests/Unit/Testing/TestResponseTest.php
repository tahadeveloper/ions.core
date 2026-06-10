<?php

declare(strict_types=1);

use Ions\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

function jsonTestResponse(array $payload, int $status = 200): TestResponse
{
    return new TestResponse(new JsonResponse($payload, $status));
}

// ---------------------------------------------------------------------------
// Status assertions
// ---------------------------------------------------------------------------

test('assertStatus passes on a matching status and returns $this for chaining', function () {
    $response = new TestResponse(new Response('hi', 201));

    expect($response->assertStatus(201))->toBe($response);
});

test('assertStatus fails with a PHPUnit assertion failure on mismatch', function () {
    $response = new TestResponse(new Response('hi', 500));

    expect(fn () => $response->assertStatus(200))->toThrow(AssertionFailedError::class);
});

test('assertOk / assertCreated / assertNoContent map to 200 / 201 / 204', function () {
    (new TestResponse(new Response('', 200)))->assertOk();
    (new TestResponse(new Response('', 201)))->assertCreated();
    (new TestResponse(new Response('', 204)))->assertNoContent();

    expect(fn () => (new TestResponse(new Response('', 404)))->assertOk())
        ->toThrow(AssertionFailedError::class);
});

test('assertNoContent also fails when a 204 carries a body', function () {
    $response = new Response('', 204);
    // Bypass Symfony's own guard by setting content after construction.
    $response->setContent('unexpected');

    expect(fn () => (new TestResponse($response))->assertNoContent())
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Redirects
// ---------------------------------------------------------------------------

test('assertRedirect passes for a redirect response, with and without a target', function () {
    $response = new TestResponse(new RedirectResponse('/login', 302));

    $response->assertRedirect()->assertRedirect('/login');
});

test('assertRedirect fails on a non-redirect response', function () {
    $response = new TestResponse(new Response('ok', 200));

    expect(fn () => $response->assertRedirect())->toThrow(AssertionFailedError::class);
});

test('assertRedirect fails when the Location target differs', function () {
    $response = new TestResponse(new RedirectResponse('/login', 302));

    expect(fn () => $response->assertRedirect('/dashboard'))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Body
// ---------------------------------------------------------------------------

test('assertSee checks the raw body', function () {
    $response = new TestResponse(new Response('<h1>Hello world</h1>'));

    $response->assertSee('Hello world');

    expect(fn () => $response->assertSee('absent'))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// JSON subset matching
// ---------------------------------------------------------------------------

test('assertJson matches a flat subset and ignores extra keys', function () {
    jsonTestResponse(['status' => 'success', 'extra' => 'ignored'])
        ->assertJson(['status' => 'success']);
});

test('assertJson matches nested arrays recursively as subsets', function () {
    jsonTestResponse([
        'data' => ['user' => ['id' => 7, 'name' => 'Ion', 'role' => 'admin']],
        'meta' => ['page' => 1],
    ])->assertJson([
        'data' => ['user' => ['id' => 7]],
    ]);
});

test('assertJson matches list entries by index', function () {
    jsonTestResponse(['tags' => ['a', 'b', 'c']])
        ->assertJson(['tags' => ['a', 'b', 'c']]);

    // Index 1 must be 'b' — a mismatch fails.
    expect(fn () => jsonTestResponse(['tags' => ['a', 'b']])->assertJson(['tags' => ['b']]))
        ->toThrow(AssertionFailedError::class);
});

test('assertJson fails when a key is missing', function () {
    expect(fn () => jsonTestResponse(['status' => 'success'])->assertJson(['missing' => 1]))
        ->toThrow(AssertionFailedError::class);
});

test('assertJson fails when a nested key is missing', function () {
    expect(fn () => jsonTestResponse(['data' => ['id' => 1]])->assertJson(['data' => ['name' => 'x']]))
        ->toThrow(AssertionFailedError::class);
});

test('assertJson fails on a value mismatch', function () {
    expect(fn () => jsonTestResponse(['status' => 'error'])->assertJson(['status' => 'success']))
        ->toThrow(AssertionFailedError::class);
});

test('assertJson fails when the subset expects an array but the actual value is scalar', function () {
    expect(fn () => jsonTestResponse(['data' => 'scalar'])->assertJson(['data' => ['id' => 1]]))
        ->toThrow(AssertionFailedError::class);
});

test('assertJson fails when the body is not valid JSON', function () {
    $response = new TestResponse(new Response('<html>not json</html>'));

    expect(fn () => $response->assertJson(['any' => 'thing']))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// JSON path
// ---------------------------------------------------------------------------

test('assertJsonPath resolves dot paths, including list indexes', function () {
    jsonTestResponse(['data' => ['user' => ['id' => 7]], 'tags' => ['a', 'b']])
        ->assertJsonPath('data.user.id', 7)
        ->assertJsonPath('tags.1', 'b');
});

test('assertJsonPath is strict about types', function () {
    expect(fn () => jsonTestResponse(['id' => 7])->assertJsonPath('id', '7'))
        ->toThrow(AssertionFailedError::class);
});

test('assertJsonPath fails on a missing path', function () {
    expect(fn () => jsonTestResponse(['id' => 7])->assertJsonPath('nope.deep', 1))
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Headers
// ---------------------------------------------------------------------------

test('assertHeader checks presence and (optionally) the exact value', function () {
    $base = new Response('ok');
    $base->headers->set('X-Custom', 'abc');
    $response = new TestResponse($base);

    $response->assertHeader('X-Custom')->assertHeader('X-Custom', 'abc');

    expect(fn () => $response->assertHeader('X-Missing'))->toThrow(AssertionFailedError::class);
    expect(fn () => $response->assertHeader('X-Custom', 'other'))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Accessors
// ---------------------------------------------------------------------------

test('status / content / headers / json / baseResponse accessors expose the wrapped response', function () {
    $base = new JsonResponse(['data' => ['id' => 9, 'tags' => ['x']]], 202);
    $base->headers->set('X-Trace', 't-1');
    $response = new TestResponse($base);

    expect($response->status())->toBe(202)
        ->and($response->content())->toBe((string) $base->getContent())
        ->and($response->headers()->get('X-Trace'))->toBe('t-1')
        ->and($response->json())->toBe(['data' => ['id' => 9, 'tags' => ['x']]])
        ->and($response->json('data.id'))->toBe(9)
        ->and($response->json('data.tags.0'))->toBe('x')
        ->and($response->json('missing'))->toBeNull()
        ->and($response->baseResponse)->toBe($base);
});

test('json() returns null when the body is not valid JSON', function () {
    $response = new TestResponse(new Response('plain text'));

    expect($response->json())->toBeNull();
});
