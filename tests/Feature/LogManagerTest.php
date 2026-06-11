<?php

use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Log\LogManager;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

beforeEach(fn () => bootFixtureKernel());

function logManager(): LogManager
{
    return Kernel::app()->get('log');
}

/** Unique per-test log filename so parallel-ish runs never collide. */
function tmpLogName(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(3)) . '.log';
}

test('single channel writes to its var/logs file', function () {
    $file = tmpLogName('lm_single');
    config(['logging.channels.t_single' => ['driver' => 'single', 'path' => $file, 'level' => 'debug']]);

    logManager()->channel('t_single')->info('hello single');

    $path = Path::logs($file);
    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('hello single');
    is_file($path) && unlink($path);
});

test('absolute channel paths are kept as-is', function () {
    $abs = sys_get_temp_dir() . '/' . tmpLogName('lm_abs');
    config(['logging.channels.t_abs' => ['driver' => 'single', 'path' => $abs]]);

    logManager()->channel('t_abs')->info('absolute path');

    expect(file_exists($abs))->toBeTrue()
        ->and(file_get_contents($abs))->toContain('absolute path');
    is_file($abs) && unlink($abs);
});

test('daily driver creates a dated filename and wires maxFiles into RotatingFileHandler', function () {
    $file = tmpLogName('lm_daily');
    config(['logging.channels.t_daily' => ['driver' => 'daily', 'path' => $file, 'days' => 14]]);

    $logger = logManager()->channel('t_daily');
    $logger->warning('daily entry');

    $dated = Path::logs(str_replace('.log', '', $file) . '-' . date('Y-m-d') . '.log');
    expect(file_exists($dated))->toBeTrue()
        ->and(file_get_contents($dated))->toContain('daily entry');

    /** @var \Monolog\Logger $logger */
    $handler = $logger->getHandlers()[0];
    expect($handler)->toBeInstanceOf(RotatingFileHandler::class);
    $prop = new ReflectionProperty(RotatingFileHandler::class, 'maxFiles');
    expect($prop->getValue($handler))->toBe(14);

    is_file($dated) && unlink($dated);
});

test('stderr driver wires a php://stderr StreamHandler at the configured level', function () {
    config(['logging.channels.t_stderr' => ['driver' => 'stderr', 'level' => 'warning']]);

    /** @var \Monolog\Logger $logger */
    $logger = logManager()->channel('t_stderr');
    $handler = $logger->getHandlers()[0];

    expect($handler)->toBeInstanceOf(StreamHandler::class)
        ->and($handler->getUrl())->toBe('php://stderr')
        ->and($handler->getLevel())->toBe(Level::Warning);
});

test('stack channel fans a record out to every member channel', function () {
    $fileA = tmpLogName('lm_stack_a');
    $fileB = tmpLogName('lm_stack_b');
    config([
        'logging.channels.t_sa'    => ['driver' => 'single', 'path' => $fileA],
        'logging.channels.t_sb'    => ['driver' => 'single', 'path' => $fileB],
        'logging.channels.t_stack' => ['driver' => 'stack', 'channels' => ['t_sa', 't_sb']],
    ]);

    logManager()->channel('t_stack')->error('fan out');

    expect(file_get_contents(Path::logs($fileA)))->toContain('fan out')
        ->and(file_get_contents(Path::logs($fileB)))->toContain('fan out');
    is_file(Path::logs($fileA)) && unlink(Path::logs($fileA));
    is_file(Path::logs($fileB)) && unlink(Path::logs($fileB));
});

test('stack() builds an ad-hoc stack over named channels', function () {
    $fileA = tmpLogName('lm_adhoc_a');
    $fileB = tmpLogName('lm_adhoc_b');
    config([
        'logging.channels.t_aa' => ['driver' => 'single', 'path' => $fileA],
        'logging.channels.t_ab' => ['driver' => 'single', 'path' => $fileB],
    ]);

    logManager()->stack(['t_aa', 't_ab'])->warning('adhoc stack');

    expect(file_get_contents(Path::logs($fileA)))->toContain('adhoc stack')
        ->and(file_get_contents(Path::logs($fileB)))->toContain('adhoc stack');
    is_file(Path::logs($fileA)) && unlink(Path::logs($fileA));
    is_file(Path::logs($fileB)) && unlink(Path::logs($fileB));
});

test('per-channel level threshold drops records below it', function () {
    $file = tmpLogName('lm_level');
    config(['logging.channels.t_errs' => ['driver' => 'single', 'path' => $file, 'level' => 'error']]);

    $logger = logManager()->channel('t_errs');
    $logger->debug('too quiet');
    expect(file_exists(Path::logs($file)))->toBeFalse();

    $logger->error('loud enough');
    expect(file_get_contents(Path::logs($file)))->toContain('loud enough')
        ->and(file_get_contents(Path::logs($file)))->not->toContain('too quiet');
    is_file(Path::logs($file)) && unlink(Path::logs($file));
});

test('unknown channel throws InvalidArgumentException', function () {
    logManager()->channel('definitely_not_configured');
})->throws(InvalidArgumentException::class);

test('unknown driver throws InvalidArgumentException', function () {
    config(['logging.channels.t_bad' => ['driver' => 'carrier-pigeon']]);
    logManager()->channel('t_bad');
})->throws(InvalidArgumentException::class);

test('channels are memoized — same instance per name', function () {
    $file = tmpLogName('lm_memo');
    config(['logging.channels.t_memo' => ['driver' => 'single', 'path' => $file]]);

    expect(logManager()->channel('t_memo'))->toBe(logManager()->channel('t_memo'));
});

test('default channel falls back to a built-in app single channel with zero config', function () {
    // The fixture app ships no config/logging.php at all.
    expect(config('logging.channels'))->toBeNull();

    $path = Path::logs('app.log');
    is_file($path) && unlink($path);

    logManager()->info('zero config still logs');

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('zero config still logs');
    is_file($path) && unlink($path);
});

test('logging.default selects the default channel for proxied PSR-3 calls', function () {
    $file = tmpLogName('lm_default');
    config([
        'logging.default'           => 't_other',
        'logging.channels.t_other'  => ['driver' => 'single', 'path' => $file],
    ]);

    logManager()->error('routed to other');

    expect(file_get_contents(Path::logs($file)))->toContain('routed to other');
    is_file(Path::logs($file)) && unlink(Path::logs($file));
});

test('redaction is applied on manager-built channels', function () {
    $file = tmpLogName('lm_redact');
    config(['logging.channels.t_redact' => ['driver' => 'single', 'path' => $file]]);

    logManager()->channel('t_redact')->error('redact me', ['password' => 'p-secret-value', 'ok' => 'v']);

    $content = (string) file_get_contents(Path::logs($file));
    expect($content)->toContain('[REDACTED]')
        ->and($content)->not->toContain('p-secret-value')
        ->and($content)->toContain('"ok":"v"');
    is_file(Path::logs($file)) && unlink(Path::logs($file));
});

test('request_id is present, stable across channels within a request, and rotates after resetForRequest', function () {
    $fileA = tmpLogName('lm_rid_a');
    $fileB = tmpLogName('lm_rid_b');
    config([
        'logging.channels.t_ra' => ['driver' => 'single', 'path' => $fileA],
        'logging.channels.t_rb' => ['driver' => 'single', 'path' => $fileB],
    ]);

    logManager()->channel('t_ra')->info('first');
    logManager()->channel('t_rb')->info('second');

    // Last request_id in the file (the memoized logger keeps its stream
    // open, so the "next request" record appends to the same file).
    $extract = function (string $file): string {
        preg_match_all('/"request_id":"([0-9a-f]{16})"/', (string) file_get_contents(Path::logs($file)), $m);
        return end($m[1]) ?: '';
    };

    $idA = $extract($fileA);
    $idB = $extract($fileB);
    expect($idA)->toHaveLength(16)->and($idB)->toBe($idA);

    Kernel::resetForRequest();

    logManager()->channel('t_ra')->info('next request');
    expect($extract($fileA))->toHaveLength(16)->not->toBe($idA);

    is_file(Path::logs($fileA)) && unlink(Path::logs($fileA));
    is_file(Path::logs($fileB)) && unlink(Path::logs($fileB));
});

test('Logs::create keeps its legacy shape: same file path, same write, request_id added additively', function () {
    $file = tmpLogName('lm_legacy');

    $logger = \Ions\Bundles\Logs::create($file);
    $logger->info('legacy surface', ['k' => 'v']);

    $path = Path::logs($file);
    $content = (string) file_get_contents($path);
    expect(file_exists($path))->toBeTrue()
        ->and($content)->toContain('legacy surface')
        ->and($content)->toContain('"k":"v"')
        ->and($content)->toMatch('/"request_id":"[0-9a-f]{16}"/');
    is_file($path) && unlink($path);
});
