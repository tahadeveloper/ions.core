<?php

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

final class MailProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('mailer')) {
            // lazy: built on first resolve, so a missing/invalid DSN only errors at send time
            $this->container->singleton('mailer', static function () {
                $dsn = sprintf(
                    'smtp://%s:%s@%s:%s',
                    rawurlencode((string) env('MAIL_USERNAME')),
                    rawurlencode((string) env('MAIL_PASSWORD')),
                    (string) env('MAIL_HOST'),
                    (string) env('MAIL_PORT'),
                );
                return new Mailer(Transport::fromDsn($dsn));
            });
        }
    }
}
