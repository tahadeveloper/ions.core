<?php

use Ions\Bundles\Path;
use Ions\Support\Log;

beforeEach(fn () => bootFixtureKernel());

test('Log::info proxies to the default channel', function () {
    $path = Path::logs('app.log');
    is_file($path) && unlink($path);

    Log::info('facade default hit');

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('facade default hit');
    is_file($path) && unlink($path);
});

test('Log::channel resolves a named channel', function () {
    $file = 'lf_channel_' . bin2hex(random_bytes(3)) . '.log';
    config(['logging.channels.t_fx' => ['driver' => 'single', 'path' => $file]]);

    Log::channel('t_fx')->error('facade channel hit');

    expect(file_get_contents(Path::logs($file)))->toContain('facade channel hit');
    is_file(Path::logs($file)) && unlink(Path::logs($file));
});

test('Log::stack fans out across the given channels', function () {
    $fileA = 'lf_stack_a_' . bin2hex(random_bytes(3)) . '.log';
    $fileB = 'lf_stack_b_' . bin2hex(random_bytes(3)) . '.log';
    config([
        'logging.channels.t_f1' => ['driver' => 'single', 'path' => $fileA],
        'logging.channels.t_f2' => ['driver' => 'single', 'path' => $fileB],
    ]);

    Log::stack(['t_f1', 't_f2'])->warning('facade stack hit');

    expect(file_get_contents(Path::logs($fileA)))->toContain('facade stack hit')
        ->and(file_get_contents(Path::logs($fileB)))->toContain('facade stack hit');
    is_file(Path::logs($fileA)) && unlink(Path::logs($fileA));
    is_file(Path::logs($fileB)) && unlink(Path::logs($fileB));
});
