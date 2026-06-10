<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use IonsFixture\Schedule\LegacySchedule;
use IonsFixture\Schedule\NewStyleSchedule;

beforeEach(function () {
    NewStyleSchedule::reset();
    LegacySchedule::reset();
    bootFixtureKernel();
});

test('GET /cron/schedule runs the due tasks for a new-style schedule class and returns a JSON summary', function () {
    config(['app.schedule_class' => NewStyleSchedule::class]);

    $response = Kernel::handle(Request::create('/cron/schedule'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');

    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['ran'])->toBe(1)
        ->and($payload['failed'])->toBe(0)
        ->and($payload['skipped'])->toBe(0)
        ->and(NewStyleSchedule::$runs)->toBe(1);
});

test('the web-cron controller is exempt from host namespace prefixing', function () {
    config(['app.schedule_class' => NewStyleSchedule::class]);

    $response = Kernel::handle(Request::create('/cron/schedule'), 'App\\');

    expect($response->getStatusCode())->toBe(200)
        ->and(NewStyleSchedule::$runs)->toBe(1);
});

test('a legacy zero-parameter App\\Schedule::boot keeps its old controller-string dispatch', function () {
    config(['app.schedule_class' => LegacySchedule::class]);

    $response = Kernel::handle(Request::create('/cron/schedule'));

    expect($response->getStatusCode())->toBe(200)
        ->and(LegacySchedule::$runs)->toBeGreaterThanOrEqual(1);
});

test('a missing schedule class yields a 404', function () {
    config(['app.schedule_class' => 'IonsFixture\\Schedule\\DoesNotExist']);

    $response = Kernel::handle(Request::create('/cron/schedule'));

    expect($response->getStatusCode())->toBe(404);
});
