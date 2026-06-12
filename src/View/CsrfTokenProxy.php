<?php

declare(strict_types=1);

namespace Ions\View;

use Ions\Bundles\Logs;

/**
 * Lazy, output-only proxy for the `_csrf_token` Twig global.
 *
 * Registered ONCE as a stable global object; its value is materialised only
 * when a template actually OUTPUTS `{{ _csrf_token }}` — at which point
 * __toString() resolves the CURRENT request's token via csrfToken('web').
 *
 * Why a proxy rather than an eager string value:
 *  - Eagerly seeding `_csrf_token` with csrfToken('web') on every env
 *    build/refresh GENERATES and WRITES the token into the session on EVERY
 *    render (csrfToken → CsrfTokenManager::getToken → SessionTokenStorage).
 *    A non-empty session makes ResponseCache::requestIsStateful() true, so NO
 *    framework-rendered page could ever be response-cached (Phase 12.5) —
 *    even a form-less public page that never references the token.
 *  - Deferring to render time means a form-less template leaves the session
 *    empty (cacheable), while a template that prints the token writes it
 *    (correctly NOT cacheable — the page embeds per-session state).
 *
 * Worker-mode safe: because the token is read lazily from the live request
 * each time, a stable proxy registered once never goes stale across requests —
 * no per-request refresh is required (it stays in requestGlobals() so
 * refreshRequestGlobals() merely re-registers the same object harmlessly).
 *
 * This proxy is OUTPUT-ONLY by design: unlike `_trans` (used in `{% if %}` /
 * comparisons), `_csrf_token` is only ever printed, so wrapping it in a
 * Stringable does not change any template's control flow.
 */
final class CsrfTokenProxy implements \Stringable
{
    /**
     * The token string resolved on first output, memoised for the proxy's
     * lifetime. Symfony's CsrfTokenManager::getToken() re-randomises its masked
     * return value on EVERY call (all valid, but different strings), so without
     * this memo `{{ _csrf_token }}` printed twice on one page would emit two
     * different values — a BC break vs the previous eager (frozen-per-request)
     * global. A fresh proxy is registered per request (refreshRequestGlobals()),
     * so the memo is naturally per-request: stable within a request, fresh after
     * Kernel::resetForRequest().
     */
    private ?string $resolved = null;

    /**
     * Resolve the CURRENT request's 'web' CSRF token (memoised), generating +
     * storing it in the session as a side effect on first output (the desired
     * behaviour: a page that prints the token is per-session state). A form-less
     * page never calls this, so its session stays empty and cacheable.
     *
     * csrfToken() uses session-backed storage that can throw in CLI/test SAPI;
     * mirroring the original eager try/catch, any failure is logged and '' is
     * returned (un-memoised, so a later successful render can still resolve it)
     * so a render never dies on token issuance.
     */
    public function __toString(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            return $this->resolved = csrfToken('web');
        } catch (\Throwable $e) {
            Logs::create('view.log')->warning('csrfToken() failed materialising _csrf_token global: ' . $e->getMessage());

            return '';
        }
    }
}
