<?php

use Ions\Bundles\Logs;

beforeEach(fn () => bootFixtureKernel()); // for Path::logs() resolution

test('Logs::create writes an error entry with context to the log file', function () {
    $file = 'logs_test_' . bin2hex(random_bytes(3)) . '.log';
    Logs::create($file)->error('boom happened', ['k' => 'v']);
    $path = \Ions\Bundles\Path::logs($file);
    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('boom happened')
        ->and(file_get_contents($path))->toContain('"k":"v"'); // monolog json-encodes context
    @unlink($path);
});

test('sensitive context keys are redacted recursively, other values intact', function () {
    $file = 'logs_redact_' . bin2hex(random_bytes(3)) . '.log';
    Logs::create($file)->error('x', [
        'password' => 'p-secret-value',
        'nested'   => ['api_key' => 'k-secret-value'],
        'ok'       => 'v',
    ]);
    $content = (string) file_get_contents(\Ions\Bundles\Path::logs($file));

    expect($content)->toContain('[REDACTED]')
        ->and($content)->not->toContain('p-secret-value')
        ->and($content)->not->toContain('k-secret-value')
        ->and($content)->toContain('"ok":"v"');
    @unlink(\Ions\Bundles\Path::logs($file));
});

test('Authorization, token and secret keys are masked case-insensitively', function () {
    $file = 'logs_redact_' . bin2hex(random_bytes(3)) . '.log';
    Logs::create($file)->info('y', [
        'Authorization' => 'Bearer abc.def.ghi',
        'access_token'  => 'tok-123',
        'CLIENT_SECRET' => 'sec-456',
        'safe'          => 'keep-me',
    ]);
    $content = (string) file_get_contents(\Ions\Bundles\Path::logs($file));

    expect($content)->not->toContain('abc.def.ghi')
        ->and($content)->not->toContain('tok-123')
        ->and($content)->not->toContain('sec-456')
        ->and($content)->toContain('keep-me');
    @unlink(\Ions\Bundles\Path::logs($file));
});
