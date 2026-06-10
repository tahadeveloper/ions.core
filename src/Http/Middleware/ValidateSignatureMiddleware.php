<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Foundation\Kernel;
use Ions\Security\UrlSigner;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests whose URL does not carry a valid (and unexpired) signature
 * produced by {@see UrlSigner} / signedRoute().
 *
 * Attach per route via the 'signed' alias configured in app.middleware_aliases:
 *
 *     Route::get('/verify-email', ..., 'verify.email')->middleware(['signed']);
 *
 * Fail-closed by design: the UrlSigner is resolved lazily inside handle(), not
 * in the constructor, so the middleware always instantiates — even with a
 * missing/short APP_KEY. A broken key then throws while the request is being
 * handled, surfacing as a 500 on the signed route instead of the middleware
 * silently failing to construct and the unsigned request being served.
 * An explicit signer can still be injected (tests / custom wiring).
 */
final class ValidateSignatureMiddleware implements MiddlewareInterface
{
    public function __construct(private ?UrlSigner $signer = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->signer()->verify($request->getRequestUri())) {
            abort(403, 'Invalid signature.');
        }

        return $next($request);
    }

    /**
     * Resolve the signer on first use: prefer the container singleton bound by
     * SecurityProvider; fall back to direct derivation so signed routes still
     * verify when the provider list was overridden without SecurityProvider.
     *
     * @throws \RuntimeException when APP_KEY is missing or shorter than 32 bytes.
     */
    private function signer(): UrlSigner
    {
        if ($this->signer === null) {
            $app = Kernel::app();
            $resolved = $app->bound('url.signer') ? $app->make('url.signer') : null;
            $this->signer = $resolved instanceof UrlSigner
                ? $resolved
                : UrlSigner::fromAppKey((string) env('APP_KEY', ''));
        }

        return $this->signer;
    }
}
