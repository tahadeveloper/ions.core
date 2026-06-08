<?php

test('kernel boots against a fixture app and exposes config', function () {
    bootFixtureKernel();
    expect(config('app.name'))->toBe('IonsFixture');
});
