<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

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

        return $logger;
    }

}
