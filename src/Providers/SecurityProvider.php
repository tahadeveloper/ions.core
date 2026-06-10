<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Security\Encrypter;
use Ions\Security\UrlSigner;

/**
 * Binds 'encrypter' ({@see Encrypter}) and 'url.signer' ({@see UrlSigner}).
 *
 * Both bindings are lazy singleton closures: registering costs nothing on the
 * hot path, and APP_KEY is only read/derived the first time a host actually
 * resolves one of them. Unlike JWT (which silently disables on a missing key),
 * these are explicit-use features — resolving without a valid APP_KEY
 * (≥ 32 bytes) throws a RuntimeException naming APP_KEY.
 *
 * Class-name aliases let the container constructor-inject Encrypter/UrlSigner
 * (e.g. into ValidateSignatureMiddleware) while sharing the same singletons.
 */
final class SecurityProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('encrypter')) {
            $this->container->singleton('encrypter', static function (): Encrypter {
                return Encrypter::fromAppKey((string) env('APP_KEY', ''));
            });
            $this->container->alias('encrypter', Encrypter::class);
        }

        if (!$this->container->bound('url.signer')) {
            $this->container->singleton('url.signer', static function (): UrlSigner {
                return UrlSigner::fromAppKey((string) env('APP_KEY', ''));
            });
            $this->container->alias('url.signer', UrlSigner::class);
        }
    }
}
