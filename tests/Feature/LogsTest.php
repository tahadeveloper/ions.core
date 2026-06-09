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
