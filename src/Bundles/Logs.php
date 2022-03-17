<?php

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
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
        if($reset_logger){
            self::reset($file_name);
        }

        // Create some handlers
        $stream = new StreamHandler(Path::logs($file_name), Logger::DEBUG);
        $firephp = new FirePHPHandler();

        // Create the main logger of the app
        $logger = new Logger('ions');
        $logger->pushHandler($stream);
        $logger->pushHandler($firephp);

        return $logger;
    }

}
