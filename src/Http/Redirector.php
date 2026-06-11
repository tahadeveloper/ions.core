<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Throwable;

/**
 * Fluent redirect builder (Phase 10.3) — returned by a bare redirect() call:
 *
 *   redirect()->to('/dashboard')                      // app_url-relative path
 *   redirect()->route('users.show', ['id' => 7])     // named-route URL
 *   redirect()->back('/fallback')                     // Referer or fallback
 *   redirect()->away('https://external.example')     // external, untouched
 *
 * Every method returns an {@see RedirectResponse}, so the flash chain
 * (->with()/->withErrors()/->withInput()/->withHeaders()) hangs off any of
 * them. The legacy static {@see \Ions\Bundles\Redirect} API is unchanged.
 */
final class Redirector
{
    /**
     * @param Request|null $request The request redirected away from (Referer
     *                              source for back(), input source for
     *                              withInput()). Null = the kernel's current
     *                              request.
     */
    public function __construct(private readonly ?Request $request = null)
    {
    }

    /**
     * Redirect to an application path; absolute http(s) URLs pass through
     * unchanged, anything else is prefixed with config('app.app_url').
     *
     * @param array<string, string|null> $headers
     */
    public function to(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
        if (preg_match('#^https?://#i', $path) !== 1) {
            $path = rtrim((string) config('app.app_url', ''), '/') . '/' . ltrim($path, '/');
        }

        return $this->make($path, $status, $headers);
    }

    /**
     * Redirect to a named route. $params fill placeholders; leftovers become
     * query parameters (same resolution as the route()/signedRoute() helpers).
     *
     * @param array<string, mixed> $params
     * @param array<string, string|null> $headers
     *
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException when the name is unknown.
     */
    public function route(string $name, array $params = [], int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->make(UrlResolver::route($name, $params), $status, $headers);
    }

    /**
     * Redirect back to the request's Referer, or to $fallback when the header
     * is absent or not same-origin (open-redirect guard — see isSafeReferer()).
     *
     * @param array<string, string|null> $headers
     */
    public function back(string $fallback = '/', int $status = 302, array $headers = []): RedirectResponse
    {
        $referer = $this->request()?->headers->get('referer');

        return is_string($referer) && $referer !== '' && $this->isSafeReferer($referer)
            ? $this->make($referer, $status, $headers)
            : $this->to($fallback, $status, $headers);
    }

    /**
     * Redirect to an external URL — never app_url-prefixed, never validated.
     *
     * @param array<string, string|null> $headers
     */
    public function away(string $url, int $status = 302, array $headers = []): RedirectResponse
    {
        return $this->make($url, $status, $headers);
    }

    /**
     * @param array<string, string|null> $headers
     */
    private function make(string $url, int $status, array $headers): RedirectResponse
    {
        $response = new RedirectResponse($url, $status, $headers);
        $response->setSourceRequest($this->request());

        return $response;
    }

    /**
     * Open-redirect guard: the Referer header is attacker-controlled, so
     * back() only honours it when it is same-origin —
     *
     *   - a path-only referer ('/orders?page=2') is same-origin by definition;
     *   - an absolute referer must be http(s) AND its host must match the
     *     current request's host or the config('app.app_url') host.
     *
     * Everything else — foreign hosts, scheme-relative '//evil.example',
     * non-web schemes (javascript:, data:), unparseable values — is rejected
     * and back() uses its fallback instead.
     */
    private function isSafeReferer(string $referer): bool
    {
        $parts = parse_url($referer);
        if ($parts === false) {
            return false;
        }

        $host = $parts['host'] ?? null;

        if ($host === null) {
            // No authority: accept plain paths only. A scheme (javascript:,
            // data:) or a '/\' prefix (browsers normalise backslashes to '//',
            // turning '/\evil' scheme-relative) is rejected.
            return !isset($parts['scheme'])
                && str_starts_with($referer, '/')
                && !str_starts_with($referer, '/\\');
        }

        // An authority requires an explicit http(s) scheme — this rejects
        // scheme-relative '//host' referers and every non-web scheme.
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $allowedHosts = array_filter([
            $this->request()?->getHost(),
            parse_url((string) config('app.app_url', ''), PHP_URL_HOST),
        ], static fn ($h): bool => is_string($h) && $h !== '');

        return in_array(strtolower($host), array_map('strtolower', $allowedHosts), true);
    }

    private function request(): ?Request
    {
        if ($this->request !== null) {
            return $this->request;
        }

        try {
            return Kernel::request();
        } catch (Throwable) {
            return null;
        }
    }
}
