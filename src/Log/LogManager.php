<?php

declare(strict_types=1);

namespace Ions\Log;

use InvalidArgumentException;
use Ions\Bundles\Path;
use Ions\Bundles\RedactionProcessor;
use Ions\Bundles\RequestIdProcessor;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Channel-based logging over Monolog, configured from config/logging.php:
 *
 *   'default'  => 'app',
 *   'channels' => [
 *       'app'    => ['driver' => 'single', 'path' => 'app.log', 'level' => 'debug'],
 *       'daily'  => ['driver' => 'daily',  'path' => 'app.log', 'days' => 14],
 *       'stderr' => ['driver' => 'stderr', 'level' => 'warning'],
 *       'stack'  => ['driver' => 'stack',  'channels' => ['app', 'stderr']],
 *   ]
 *
 * Relative 'path' values resolve under var/logs; absolute paths are kept.
 * When no config/logging.php exists at all, a built-in 'app' channel
 * (single → var/logs/app.log) keeps zero-config logging working.
 *
 * Every built logger gets the {@see RedactionProcessor} (secret masking) and
 * the {@see RequestIdProcessor} (extra.request_id correlation — the same id
 * on every channel within one request). Channels are memoized per name.
 * Unknown channels and drivers fail loud with InvalidArgumentException.
 *
 * Bound lazily as 'log' by {@see \Ions\Providers\LogProvider}; hosts consume
 * it through the {@see \Ions\Support\Log} facade. PSR-3 methods called on the
 * manager itself (info/error/…) proxy to the default channel via __call.
 *
 * @method void emergency(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void alert(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void critical(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void error(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void warning(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void notice(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void info(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void debug(string|\Stringable $message, array<string, mixed> $context = [])
 * @method void log(mixed $level, string|\Stringable $message, array<string, mixed> $context = [])
 */
class LogManager
{
    /** @var array<string, Logger> Built channels, memoized by name. */
    private array $channels = [];

    /**
     * Resolve a configured channel (the default channel when $name is null).
     */
    public function channel(?string $name = null): LoggerInterface
    {
        return $this->monolog($name ?? $this->defaultChannel());
    }

    /**
     * Ad-hoc stack: one logger fanning every record out to all the named
     * channels' handlers (each member keeps its own level threshold).
     *
     * @param list<string> $channels
     */
    public function stack(array $channels): LoggerInterface
    {
        return $this->prepare($this->aggregate('stack', $channels));
    }

    /**
     * Proxy PSR-3 calls (Log::info(...) etc.) to the default channel.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->channel()->{$method}(...$parameters);
    }

    /**
     * The memoized concrete Monolog logger for a channel name.
     */
    private function monolog(string $name): Logger
    {
        return $this->channels[$name] ??= $this->build($name, $this->configurationFor($name));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(string $name, array $config): Logger
    {
        $driver = (string) ($config['driver'] ?? '');
        $level = $this->level($name, $config);

        $logger = match ($driver) {
            'single' => (new Logger($name))->pushHandler(
                new StreamHandler($this->path((string) ($config['path'] ?? $name . '.log')), $level)
            ),
            'daily' => (new Logger($name))->pushHandler(
                new RotatingFileHandler(
                    $this->path((string) ($config['path'] ?? $name . '.log')),
                    (int) ($config['days'] ?? 7),
                    $level
                )
            ),
            'stderr' => (new Logger($name))->pushHandler(new StreamHandler('php://stderr', $level)),
            'stack' => $this->aggregate($name, array_values(array_map(
                strval(...),
                (array) ($config['channels'] ?? [])
            ))),
            default => throw new InvalidArgumentException(
                "Log driver [$driver] for channel [$name] is not supported (single/daily/stderr/stack)."
            ),
        };

        return $this->prepare($logger);
    }

    /**
     * A logger aggregating the handlers of every member channel. Member-level
     * thresholds travel with the handlers; processors are (re)attached once
     * on the aggregate by prepare(), so records are redacted exactly once.
     *
     * @param list<string> $channels
     */
    private function aggregate(string $name, array $channels): Logger
    {
        $logger = new Logger($name);

        foreach ($channels as $channel) {
            foreach ($this->monolog($channel)->getHandlers() as $handler) {
                $logger->pushHandler($handler);
            }
        }

        return $logger;
    }

    /**
     * Attach the framework-wide processors every channel must carry.
     */
    private function prepare(Logger $logger): Logger
    {
        $logger->pushProcessor(new RedactionProcessor());
        $logger->pushProcessor(new RequestIdProcessor());

        return $logger;
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationFor(string $name): array
    {
        $config = config('logging.channels.' . $name);

        if (is_array($config)) {
            /** @var array<string, mixed> $config */
            return $config;
        }

        // Built-in fallback: zero-config hosts (no config/logging.php) keep a
        // working 'app' channel writing var/logs/app.log, like Logs::create().
        if ($name === 'app') {
            return ['driver' => 'single', 'path' => 'app.log', 'level' => 'debug'];
        }

        throw new InvalidArgumentException("Log channel [$name] is not defined in config/logging.php.");
    }

    private function defaultChannel(): string
    {
        return (string) config('logging.default', 'app');
    }

    /**
     * Resolve a channel's minimum level ('debug' when unset). Explicit match
     * (rather than Level::fromName) so a typo'd level fails loud with the
     * channel name in the message.
     *
     * @param array<string, mixed> $config
     */
    private function level(string $name, array $config): Level
    {
        $value = $config['level'] ?? 'debug';

        if ($value instanceof Level) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => throw new InvalidArgumentException(
                'Invalid log level [' . (string) $value . "] for channel [$name]."
            ),
        };
    }

    /**
     * Relative paths land under var/logs (like Logs::create); absolute
     * paths — POSIX or Windows drive-letter — are kept verbatim.
     */
    private function path(string $path): string
    {
        $absolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;

        return $absolute ? $path : Path::logs($path);
    }
}
