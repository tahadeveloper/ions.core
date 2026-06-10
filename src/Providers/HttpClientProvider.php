<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Binds 'http' — the shared Symfony HTTP client behind {@see \Ions\Support\Http}
 * and {@see \Ions\Http\Client}.
 *
 * The binding is a lazy singleton closure: registering it costs nothing on
 * the hot path, and the client (curl/native transport selection included) is
 * only built the first time a host actually resolves 'http'.
 */
final class HttpClientProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('http')) {
            $this->container->singleton('http', static function (): HttpClientInterface {
                return HttpClient::create();
            });
        }
    }
}
