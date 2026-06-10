<?php

declare(strict_types=1);

use Ions\Http\Client;
use Ions\Testing\Fakes\HttpFake;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpClient\Response\MockResponse;

test('the fake records requests and answers 200 with an empty body by default', function () {
    $fake = new HttpFake();
    $client = new Client($fake);

    $response = $client->get('https://api.example.test/users', ['page' => 1]);

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('')
        ->and($fake->sent())->toHaveCount(1)
        ->and($fake->sent()[0]['method'])->toBe('GET')
        ->and($fake->sent()[0]['url'])->toBe('https://api.example.test/users?page=1');
});

test('an associative map fakes responses per url pattern (wildcards supported)', function () {
    $fake = new HttpFake([
        'https://api.example.test/users*' => new MockResponse('{"id":7}', ['http_code' => 201]),
        'https://api.example.test/plain' => 'plain body',
    ]);
    $client = new Client($fake);

    $created = $client->json('https://api.example.test/users', ['name' => 'amr']);
    $plain = $client->get('https://api.example.test/plain');
    $unmatched = $client->get('https://other.example.test/x');

    expect($created->status())->toBe(201)
        ->and($created->json('id'))->toBe(7)
        ->and($plain->status())->toBe(200)
        ->and($plain->body())->toBe('plain body')
        ->and($unmatched->status())->toBe(200)
        ->and($unmatched->body())->toBe('');
});

test('a sequential list of responses is consumed in order', function () {
    $fake = new HttpFake([
        new MockResponse('first', ['http_code' => 500]),
        'second',
    ]);
    $client = new Client($fake);

    expect($client->get('https://api.example.test/a')->status())->toBe(500)
        ->and($client->get('https://api.example.test/b')->body())->toBe('second');
});

test('a callable factory receives the resolved request per MockHttpClient semantics', function () {
    $fake = new HttpFake(static function (string $method, string $url): MockResponse {
        return new MockResponse(strtolower($method) . ' ' . $url, ['http_code' => 202]);
    });
    $client = new Client($fake);

    $response = $client->post('https://api.example.test/jobs', ['run' => 'now']);

    expect($response->status())->toBe(202)
        ->and($response->body())->toBe('post https://api.example.test/jobs');
});

test('assertSent() matches an exact url and a wildcard pattern', function () {
    $fake = new HttpFake();
    (new Client($fake))->get('https://api.example.test/users/7');

    $fake->assertSent('https://api.example.test/users/7')
        ->assertSent('https://api.example.test/*');
});

test('assertSent() fails with an Ions-worded message when no request matches', function () {
    $fake = new HttpFake();
    (new Client($fake))->get('https://api.example.test/users');

    expect(fn () => $fake->assertSent('https://other.example.test/*'))
        ->toThrow(AssertionFailedError::class, 'Expected an HTTP request matching');
});

test('assertSent() fails when nothing was sent at all', function () {
    expect(fn () => (new HttpFake())->assertSent('https://api.example.test/*'))
        ->toThrow(AssertionFailedError::class, 'No HTTP requests were sent at all');
});

test('assertSent() accepts a filter callable receiving method, url and options', function () {
    $fake = new HttpFake();
    (new Client($fake))->withToken('secret')->json('https://api.example.test/users', ['name' => 'amr']);

    $fake->assertSent(static function (string $method, string $url, array $options): bool {
        return $method === 'POST'
            && $url === 'https://api.example.test/users'
            && str_contains(implode("\n", $options['headers']), 'Authorization: Bearer secret');
    });

    expect(fn () => $fake->assertSent(static fn (string $method): bool => $method === 'DELETE'))
        ->toThrow(AssertionFailedError::class, 'Expected an HTTP request matching the given filter');
});

test('assertSentCount() passes on the exact count and fails otherwise', function () {
    $fake = new HttpFake();
    $client = new Client($fake);
    $client->get('https://api.example.test/a');
    $client->get('https://api.example.test/b');

    $fake->assertSentCount(2);

    expect(fn () => $fake->assertSentCount(3))
        ->toThrow(AssertionFailedError::class, 'Expected exactly 3 HTTP request(s), got 2.');
});

test('assertNothingSent() passes when idle and fails after a request', function () {
    $fake = new HttpFake();
    $fake->assertNothingSent();

    (new Client($fake))->get('https://api.example.test/ping');

    expect(fn () => $fake->assertNothingSent())
        ->toThrow(AssertionFailedError::class, 'Expected no HTTP requests to be sent, but 1 were.');
});

test('assertions are chainable', function () {
    $fake = new HttpFake();
    (new Client($fake))->get('https://api.example.test/a');

    expect($fake->assertSent('https://api.example.test/a')->assertSentCount(1))->toBe($fake);
});
