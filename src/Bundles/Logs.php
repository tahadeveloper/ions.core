<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Legacy single-file logging surface, kept byte-compatible: a fresh
 * non-memoized Logger('ions') writing var/logs/{$file_name} at Debug level
 * with Monolog's default line format.
 *
 * New code should prefer the channel system — {@see \Ions\Support\Log}
 * (`Log::info()`, `Log::channel()`, `Log::stack()`) over config/logging.php
 * (drivers single/daily/stderr/stack, per-channel levels). This class stays
 * for the framework's internal fixed-file logs and existing host apps.
 */
class Logs extends Singleton
{
    public static function reset($file_name): void
    {
        if (file_exists(Path::logs($file_name))) {
            unlink(Path::logs($file_name));
        }
    }

    public static function create(string $file_name = 'app.log', bool $reset_logger = false): Logger
    {
        if ($reset_logger) {
            self::reset($file_name);
        }

        // Create some handlers
        $stream = new StreamHandler(Path::logs($file_name), Level::Debug);

        // Create the main logger of the app
        $logger = new Logger('ions');
        $logger->pushHandler($stream);

        // Mask secret-bearing context values (password/token/secret/
        // authorization/api_key, recursive) before anything hits the file.
        $logger->pushProcessor(new RedactionProcessor());

        // Same per-request correlation id as the channel system (additive:
        // extra.request_id only — the line shape is otherwise unchanged).
        $logger->pushProcessor(new RequestIdProcessor());

        return $logger;
    }

}
