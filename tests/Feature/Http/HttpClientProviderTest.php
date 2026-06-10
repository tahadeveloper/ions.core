<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Http;
use Ions\Support\Request;
use Ions\Testing\Fakes\HttpFake;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

beforeEach(fn () => bootFixtureKernel());

test("the 'http' binding is a lazy singleton resolving to a Symfony client", function () {
    expect(Kernel::app()->resolved('http'))->toBeFalse();

    $client = Kernel::app()->get('http');

    expect($client)->toBeInstanceOf(HttpClientInterface::class)
        ->and(Kernel::app()->get('http'))->toBe($client);
});

test("a normal request through the kernel never resolves 'http' (zero hot-path cost)", function () {
    $response = Kernel::handle(Request::create('/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and(Kernel::app()->resolved('http'))->toBeFalse();
});

test('Http::fake() swaps the binding, returns the fake, and facade calls record into it', function () {
    $fake = Http::fake();

    expect($fake)->toBeInstanceOf(HttpFake::class)
        ->and(Kernel::app()->get('http'))->toBe($fake);

    $response = Http::get('https://api.example.test/users');

    expect($response->status())->toBe(200);
    $fake->assertSent('https://api.example.test/users');
    Http::assertSent('https://api.example.test/*');
    Http::assertSentCount(1);
});

test('the fluent facade chain routes through the installed fake', function () {
    Http::fake(['https://api.example.test/*' => new MockResponse('{"ok":true}', ['http_code' => 201])]);

    $response = Http::withToken('secret')->timeout(3)->json('https://api.example.test/users', ['name' => 'amr']);

    expect($response->status())->toBe(201)
        ->and($response->json('ok'))->toBeTrue();

    Http::assertSent(static function (string $method, string $url, array $options): bool {
        return $method === 'POST'
            && str_contains(implode("\n", $options['headers']), 'Authorization: Bearer secret');
    });
});

test('Http::assertNothingSent() works through the facade', function () {
    Http::fake();
    Http::assertNothingSent();
});

test('facade assertions without an installed fake fail pointing at Http::fake()', function () {
    expect(fn () => Http::assertSent('https://api.example.test/*'))
        ->toThrow(RuntimeException::class, 'Http::fake()');
});
