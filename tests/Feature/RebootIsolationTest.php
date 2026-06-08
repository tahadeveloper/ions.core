<?php

test('re-booting against a different fixture loads the new config (no cross-contamination)', function () {
    bootFixtureKernel(__DIR__ . '/../fixtures/app');
    expect(config('app.name'))->toBe('IonsFixture');

    bootFixtureKernel(__DIR__ . '/../fixtures/app2');
    expect(config('app.name'))->toBe('IonsFixture2');
});
