<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Foundation\Kernel;
use Ions\Session\Flash;
use Ions\Support\Arr;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Fluent redirect response with session-flash conveniences (Phase 10.3).
 *
 * Created by the redirect()/back() helpers and {@see Redirector}. Extends
 * Symfony's RedirectResponse, so it flows through ResponseNormalizer's
 * Response branch (and any middleware) untouched.
 *
 * Design decision: flash data is written IMMEDIATELY when with()/withErrors()/
 * withInput() is called — the session is always available at that point and
 * deferring to send time would silently drop flashes for responses that are
 * returned (normal pipeline flow) rather than send()-ed.
 *
 * Default excluded input keys mirror Laravel's $dontFlash and are
 * configurable via config('app.forms.dont_flash').
 */
class RedirectResponse extends SymfonyRedirectResponse
{
    private const DEFAULT_DONT_FLASH = ['password', 'password_confirmation', 'current_password'];

    /** The request whose input withInput() flashes; null = the kernel's current request. */
    private ?Request $sourceRequest = null;

    /**
     * Redirects are per-user (the Location often derives from the Referer or
     * session state, and the flash side effects are user-specific), so they
     * must never be publicly cacheable. Symfony's default is the conservative
     * 'no-cache, private'; we pin 'no-store, private' explicitly — unless the
     * caller passed an explicit Cache-Control header.
     *
     * @param array<string, string|null> $headers
     */
    public function __construct(string $url, int $status = 302, array $headers = [])
    {
        parent::__construct($url, $status, $headers);

        if (!array_key_exists('cache-control', array_change_key_case($headers, CASE_LOWER))) {
            $this->headers->set('Cache-Control', 'no-store, private');
        }
    }

    /**
     * Pin the request that withInput() reads from (the request being redirected
     * away from). Falls back to Kernel::request() when never called.
     *
     * @internal Used by Redirector and the ExceptionHandler.
     */
    public function setSourceRequest(?Request $request): static
    {
        $this->sourceRequest = $request;

        return $this;
    }

    /**
     * Flash one key/value — or an array of them — for the next request.
     *
     * @param string|array<string, mixed> $key
     */
    public function with(string|array $key, mixed $value = null): static
    {
        foreach (is_array($key) ? $key : [$key => $value] as $k => $v) {
            Flash::put((string) $k, $v);
        }

        return $this;
    }

    /**
     * Flash validation errors for the next request's errors() bag.
     *
     * @param ErrorBag|array<array-key, mixed> $errors Field => message(s), or a prebuilt bag.
     */
    public function withErrors(ErrorBag|array $errors): static
    {
        $bag = $errors instanceof ErrorBag ? $errors : new ErrorBag($errors);
        Flash::put(Flash::ERRORS, $bag->messages());

        return $this;
    }

    /**
     * Flash the source request's input for the next request's old() helper.
     *
     * Keys listed in config('app.forms.dont_flash') — password,
     * password_confirmation and current_password by default — are never
     * flashed.
     *
     * @param list<string>|null $only When given, only these input keys are flashed.
     */
    public function withInput(?array $only = null): static
    {
        $request = $this->sourceRequest ?? self::currentRequest();
        if ($request === null) {
            return $this;
        }

        /** @var array<string, mixed> $input */
        $input = $request->all();
        if ($only !== null) {
            $input = Arr::only($input, $only);
        }

        $dontFlash = (array) config('app.forms.dont_flash', self::DEFAULT_DONT_FLASH);
        $input = Arr::except($input, $dontFlash);

        Flash::put(Flash::OLD_INPUT, $input);

        return $this;
    }

    /**
     * Set several headers on the response in one call.
     *
     * @param array<string, string|null> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers->set($name, $value);
        }

        return $this;
    }

    private static function currentRequest(): ?Request
    {
        try {
            return Kernel::request();
        } catch (Throwable) {
            return null;
        }
    }
}
