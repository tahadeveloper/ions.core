<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| /up health endpoint (Phase 10.6)
|--------------------------------------------------------------------------
| The kernel registers a built-in /up route (same mechanism as
| /cron/schedule) pointing at Ions\Http\HealthController::up:
|
|  - GET /up                 -> 200 'ok' plain-text liveness probe
|  - GET /up?checks=1        -> doctor JSON, gated by app.health.token
|  - app.health.enabled=false -> the route is not registered at all (404)
*/

beforeEach(fn () => bootFixtureKernel());

test('GET /up returns a plain 200 ok liveness response', function () {
    $response = Kernel::handle(Request::create('/up'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('ok')
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/plain');
});

test('the /up response is never publicly cacheable (no-store survives the web cache headers)', function () {
    $response = Kernel::handle(Request::create('/up'));

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('max-age'))->toBeFalse();
});

test('GET /up?checks=1 without a configured token is 403', function () {
    // No app.health.token in the fixture config — checks are locked out.
    $response = Kernel::handle(Request::create('/up?checks=1&token=anything'));

    expect($response->getStatusCode())->toBe(403);
});

test('GET /up?checks=1 with a mismatched token is 403', function () {
    config(['app.health.token' => 'sekret-token']);

    $response = Kernel::handle(Request::create('/up?checks=1&token=wrong'));

    expect($response->getStatusCode())->toBe(403);
});

test('GET /up?checks=1 with the right token returns the doctor JSON (200 even with failures)', function () {
    config(['app.health.token' => 'sekret-token']);

    $response = Kernel::handle(Request::create('/up?checks=1&token=sekret-token'));

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode((string) $response->getContent(), true);

    // Same shape as `ions doctor --json`: {checks, summary, ok}.
    expect($payload)->toBeArray()
        ->and($payload)->toHaveKeys(['checks', 'summary', 'ok'])
        ->and($payload['checks'])->toBeArray()->not->toBeEmpty()
        ->and($payload['checks'][0])->toHaveKeys(['id', 'label', 'status', 'message'])
        ->and($payload['summary'])->toHaveKeys(['ok', 'info', 'warn', 'fail', 'skip'])
        ->and($payload['ok'])->toBeBool();
});

test('app.health.enabled => false unregisters the /up route entirely (404)', function () {
    config(['app.health.enabled' => false]);

    $response = Kernel::handle(Request::create('/up'));

    expect($response->getStatusCode())->toBe(404);
});
