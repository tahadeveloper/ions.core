<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Support\Request;

/**
 * Applies the configured trusted-proxy settings to the Symfony Request CLASS
 * static. Extracted verbatim from Kernel (12.7) — pure move, no behavior change.
 *
 * @internal Collaborator of {@see Kernel}; not part of the public API.
 */
final class TrustedProxies
{
    /**
     * Apply config('app.trusted_proxies') to the Request CLASS static.
     *
     * Entries are proxy IPs or CIDR ranges passed straight to Symfony's
     * Request::setTrustedProxies(). The wildcard '*' (Laravel parity) means
     * "trust the directly connecting peer": with a request at hand its
     * REMOTE_ADDR is used; otherwise Symfony's literal 'REMOTE_ADDR' token is
     * passed, which Symfony substitutes from $_SERVER at set time (classic
     * FPM boot) and silently drops when unavailable (CLI) — which is why
     * handle() re-applies this per request.
     *
     * No-op when the config key is empty: serving directly (no proxy) needs
     * no trust, and X-Forwarded-* headers from clients stay untrusted.
     *
     * @param Request|null $request The request being handled, when available.
     * @return void
     */
    public static function apply(?Request $request = null): void
    {
        $proxies = (array) config('app.trusted_proxies', []);
        if ($proxies === []) {
            // Deliberate early return (not setTrustedProxies([])): preserves any
            // manual setTrustedProxies() call; a config flip to empty takes
            // effect on the next boot/resetForTesting.
            return;
        }

        $resolved = [];
        foreach ($proxies as $proxy) {
            if ($proxy === '*') {
                $peer = $request?->server->get('REMOTE_ADDR');
                $resolved[] = is_string($peer) && $peer !== '' ? $peer : 'REMOTE_ADDR';
                continue;
            }
            $resolved[] = (string) $proxy;
        }

        // Symfony's setTrustedProxies() narrows the header-set to int<0,63>;
        // headerSet() returns a plain int (it passes power-user int bitmasks
        // through unchanged). The composed Request::HEADER_* values always fall
        // in range — ignored at the vendor boundary rather than constraining the
        // pass-through.
        /** @phpstan-ignore argument.type */
        Request::setTrustedProxies($resolved, self::headerSet());
    }

    /**
     * Resolve config('app.trusted_proxy_headers') to a Symfony header-set
     * bitmask. Friendly strings (matched case-insensitively):
     *
     *   'xff' (default) -> X-Forwarded-For | -Host | -Port | -Proto
     *   'aws-elb'       -> Request::HEADER_X_FORWARDED_AWS_ELB
     *   'traefik'       -> Request::HEADER_X_FORWARDED_TRAEFIK
     *   'forwarded'     -> Request::HEADER_FORWARDED (RFC 7239)
     *
     * An int is passed through unchanged so power users can compose any
     * Request::HEADER_* bitmask directly. Unknown strings throw (fail
     * closed): a typo'd 'aws_elb' silently falling back to the 'xff'
     * superset would re-enable X-Forwarded-Host trust the operator meant
     * to exclude.
     *
     * @return int
     * @throws \InvalidArgumentException on an unrecognized string value
     */
    public static function headerSet(): int
    {
        $configured = config('app.trusted_proxy_headers', 'xff');
        if (is_int($configured)) {
            return $configured;
        }

        return match (strtolower((string) $configured)) {
            'xff' => Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            'aws-elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'traefik' => Request::HEADER_X_FORWARDED_TRAEFIK,
            'forwarded' => Request::HEADER_FORWARDED,
            default => throw new \InvalidArgumentException(sprintf(
                "Unknown app.trusted_proxy_headers value '%s' — use 'xff', 'aws-elb', 'traefik', 'forwarded', or a Request::HEADER_* int bitmask.",
                (string) $configured
            )),
        };
    }
}
