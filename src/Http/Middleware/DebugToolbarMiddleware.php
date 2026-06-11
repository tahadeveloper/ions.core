<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Debug toolbar lite (Phase 10.6).
 *
 * Attached to the WEB middleware stack only when APP_DEBUG is truthy at
 * stack-build time (Kernel::defaultMiddleware()), so production requests pay
 * zero cost — the middleware is simply never constructed. Escape hatch while
 * debugging: config('app.debug_toolbar' => false).
 *
 * After the inner pipeline runs, a small fixed-position footer bar is
 * injected before the document's closing </body> tag showing: request wall
 * time (middleware start -> end), the matched route name/path, query count +
 * total ms (from the connection's query log when config('database.query_log')
 * is on, 'log off' otherwise), peak memory, and the PHP + Ions versions.
 *
 * Injection rules — the toolbar must NEVER break a response:
 *  - only string bodies containing </body> are touched (JSON, redirects,
 *    streamed/binary responses pass through byte-identical);
 *  - only when the Content-Type is text/html or not set yet (Symfony only
 *    defaults it to text/html at prepare()/send time);
 *  - the whole injection is wrapped in a try/catch — any failure returns
 *    the original response untouched.
 */
final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        try {
            return $this->inject($request, $response, $start);
        } catch (Throwable) {
            return $response;
        }
    }

    private function inject(Request $request, Response $response, float $start): Response
    {
        if (config('app.debug_toolbar', true) === false) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content)) {
            return $response;
        }

        $position = strripos($content, '</body>');
        if ($position === false) {
            return $response;
        }

        $bar = $this->render($request, (microtime(true) - $start) * 1000);

        $response->setContent(substr($content, 0, $position) . $bar . substr($content, $position));
        // A controller-set Content-Length would now be stale -> truncated HTML.
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function render(Request $request, float $wallMs): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $route = (string) ($request->attributes->get('_route') ?? '');
        $target = $request->getMethod() . ' ' . $request->getPathInfo()
            . ($route !== '' && !preg_match('/^[A-Za-z0-9]{10}_/', $route) ? " ({$route})" : '');

        $segments = [
            sprintf('%.1f ms', $wallMs),
            $e($target),
            'queries: ' . $e($this->queries()),
            sprintf('mem %.1f MB', memory_get_peak_usage(true) / 1048576),
            $e('PHP ' . PHP_VERSION . ' · Ions ' . self::ionsVersion()),
        ];

        return '<div id="ions-debug-toolbar" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;'
            . 'display:flex;gap:1.25rem;align-items:center;padding:.35rem .75rem;'
            . 'background:#16181d;color:#9aa4b2;border-top:1px solid #2c313a;'
            . "font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;\">"
            . '<span style="color:#7aa2f7;font-weight:700;">ions</span>'
            . '<span>' . implode('</span><span>', $segments) . '</span>'
            . '<button type="button" onclick="document.getElementById(\'ions-debug-toolbar\').remove()" '
            . 'style="margin-left:auto;background:none;border:0;color:#9aa4b2;cursor:pointer;font-size:14px;">&times;</button>'
            . '</div>';
    }

    /**
     * Query count + total time from the default connection's query log, or
     * 'log off' when config('database.query_log') is disabled (the log only
     * accumulates when DatabaseProvider enabled it). Any failure (no db
     * binding, no connection) degrades to 'n/a'.
     */
    private function queries(): string
    {
        try {
            if (!config('database.query_log', false)) {
                return 'log off';
            }

            $container = \Ions\Foundation\Kernel::app();
            if (!$container->bound('db')) {
                return 'n/a';
            }

            /** @var array<int, array{query: string, time: float|null}> $log */
            $log = $container->get('db')->getConnection()->getQueryLog();
            $total = 0.0;
            foreach ($log as $entry) {
                $total += (float) ($entry['time'] ?? 0);
            }

            return sprintf('%d (%.1f ms)', count($log), $total);
        } catch (Throwable) {
            return 'n/a';
        }
    }

    /**
     * The installed ionzile/core version via composer runtime metadata; in
     * checkouts where the package is the root (this repo's own test suite)
     * the branch alias is returned, and any failure degrades to 'dev'.
     */
    private static function ionsVersion(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('ionzile/core') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
