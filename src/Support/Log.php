<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Thin static facade over the container-bound channel logger ('log', bound
 * by {@see \Ions\Providers\LogProvider}).
 *
 *   Log::info('User created', ['id' => $id]);     // default channel
 *   Log::channel('audit')->warning('...');        // named channel
 *   Log::stack(['app', 'stderr'])->error('...');  // ad-hoc stack
 *
 * Channels are defined in config/logging.php (drivers: single/daily/stderr/
 * stack); every channel carries secret redaction and the per-request
 * `extra.request_id` correlation id. See docs/logging.md.
 *
 * @method static void emergency(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void alert(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void critical(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void error(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void warning(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void notice(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void info(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void debug(string|\Stringable $message, array<string, mixed> $context = [])
 * @method static void log(mixed $level, string|\Stringable $message, array<string, mixed> $context = [])
 */
final class Log
{
    /**
     * The container-bound channel manager.
     */
    public static function manager(): LogManager
    {
        /** @var LogManager $manager */
        $manager = Kernel::app()->get('log');

        return $manager;
    }

    /**
     * Resolve a configured channel (default channel when $name is null).
     */
    public static function channel(?string $name = null): LoggerInterface
    {
        return self::manager()->channel($name);
    }

    /**
     * Ad-hoc stack fanning records out to every named channel.
     *
     * @param list<string> $channels
     */
    public static function stack(array $channels): LoggerInterface
    {
        return self::manager()->stack($channels);
    }

    /**
     * Proxy PSR-3 statics (Log::info(...) etc.) to the manager, which routes
     * them to the default channel.
     *
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::manager()->{$method}(...$arguments);
    }
}
