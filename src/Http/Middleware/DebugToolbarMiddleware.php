<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Debug toolbar (Phase 10.6, expandable in 14.3).
 *
 * Attached to the WEB middleware stack only when APP_DEBUG is truthy at
 * stack-build time (MiddlewareStack::defaults()), so production requests pay
 * zero cost — the middleware is simply never constructed. Escape hatch while
 * debugging: config('app.debug_toolbar' => false).
 *
 * After the inner pipeline runs, a fixed-position footer bar is injected before
 * the document's closing </body> tag. The bar is a collapsed strip — request
 * wall time, the matched route/target, query count + total ms, peak memory and
 * the PHP + Ions versions — whose segments are buttons that EXPAND a detail
 * panel above the strip (queries SQL list, request meta, timing/memory). The
 * expand/collapse state is ephemeral (a tiny inlined vanilla script, no
 * cookies/localStorage). Styles + script are inlined ONCE as part of the bar
 * fragment and SCOPED under `#ions-debug-toolbar` so they never clobber the
 * host page's CSS (even when DesignSystem is already inlined on the page).
 *
 * Injection rules — the toolbar must NEVER break a response:
 *  - only string bodies containing </body> are touched (JSON, redirects,
 *    streamed/binary responses pass through byte-identical);
 *  - only when the Content-Type is text/html or not set yet (Symfony only
 *    defaults it to text/html at prepare()/send time);
 *  - the now-stale Content-Length header is removed after injecting;
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

        $bar = $this->render($request, $response, (microtime(true) - $start) * 1000);

        $response->setContent(substr($content, 0, $position) . $bar . substr($content, $position));
        // A controller-set Content-Length would now be stale -> truncated HTML.
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function render(Request $request, Response $response, float $wallMs): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $route = (string) ($request->attributes->get('_route') ?? '');
        $routeLabel = $route !== '' && !preg_match('/^[A-Za-z0-9]{10}_/', $route) ? $route : '—';
        $target = $request->getMethod() . ' ' . $request->getPathInfo()
            . ($routeLabel !== '—' ? " ({$routeLabel})" : '');

        $log = $this->queryLog();
        $queriesSummary = $this->queriesSummary($log);
        $memory = sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576);
        $version = 'PHP ' . PHP_VERSION . ' · Ions ' . self::ionsVersion();

        // --- Collapsed strip: each labelled segment is a toggle button. ---
        $strip = '<button type="button" class="idt-seg idt-brand" data-idt-toggle="timing">ions</button>'
            . $this->segment('timing', sprintf('%.1f ms', $wallMs), $e)
            . $this->segment('request', $target, $e)
            . $this->segment('queries', 'queries: ' . $queriesSummary, $e)
            . $this->segment('timing', 'mem ' . $memory, $e)
            . $this->segment('timing', $version, $e)
            . '<button type="button" class="idt-close" aria-label="Close" '
            . 'onclick="document.getElementById(\'ions-debug-toolbar\').remove()">&times;</button>';

        // --- Panels (rendered above the strip, hidden until toggled). ---
        $panels = $this->queriesPanel($log, $e)
            . $this->requestPanel($request, $response, $routeLabel, $e)
            . $this->timingPanel($wallMs, $memory, $version, $e);

        return '<div id="ions-debug-toolbar"><style>' . $this->styles() . '</style>'
            . '<div class="idt-panels">' . $panels . '</div>'
            . '<div class="idt-strip">' . $strip . '</div>'
            . '<script>' . $this->script() . '</script>'
            . '</div>';
    }

    /**
     * @param callable(string):string $e
     */
    private function segment(string $panel, string $text, callable $e): string
    {
        return '<button type="button" class="idt-seg" data-idt-toggle="' . $e($panel) . '">'
            . $e($text) . '</button>';
    }

    /**
     * The queries detail panel: the full SQL list with per-query time and a
     * binding count (values are NOT shown to avoid leaking data on a shared
     * screen). Every SQL string is HTML-escaped — a query may contain markup.
     *
     * @param array<int, array{query: string, bindings?: array<mixed>, time?: float|null}>|null $log
     * @param callable(string):string                                                            $e
     */
    private function queriesPanel(?array $log, callable $e): string
    {
        $body = '';
        if ($log === null) {
            $body = '<p class="idt-hint">set database.query_log to capture SQL</p>';
        } elseif ($log === []) {
            $body = '<p class="idt-hint">no queries</p>';
        } else {
            $rows = '';
            foreach ($log as $i => $entry) {
                $sql = $e((string) ($entry['query'] ?? ''));
                $time = sprintf('%.2f ms', (float) ($entry['time'] ?? 0));
                $bindings = count($entry['bindings'] ?? []);
                $rows .= '<tr><td class="idt-q-n">' . ($i + 1) . '</td>'
                    . '<td class="idt-q-sql"><code>' . $sql . '</code>'
                    . '<span class="idt-q-meta">' . $bindings . ' binding' . ($bindings === 1 ? '' : 's') . '</span></td>'
                    . '<td class="idt-q-t">' . $time . '</td></tr>';
            }
            $body = '<table class="idt-q-table">' . $rows . '</table>';
        }

        return '<div class="idt-panel" data-idt-panel="queries">' . $body . '</div>';
    }

    /**
     * @param callable(string):string $e
     */
    private function requestPanel(Request $request, Response $response, string $routeLabel, callable $e): string
    {
        $contentType = (string) $response->headers->get('Content-Type', 'text/html');

        $rows = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => $routeLabel,
            'status' => (string) $response->getStatusCode(),
            'content-type' => $contentType,
        ];

        $html = '';
        foreach ($rows as $key => $value) {
            $html .= '<tr><td>' . $e($key) . '</td><td>' . $e($value) . '</td></tr>';
        }

        return '<div class="idt-panel" data-idt-panel="request"><table class="idt-kv">' . $html . '</table></div>';
    }

    /**
     * @param callable(string):string $e
     */
    private function timingPanel(float $wallMs, string $memory, string $version, callable $e): string
    {
        $rows = [
            'wall time' => sprintf('%.1f ms', $wallMs),
            'peak memory' => $memory,
            'runtime' => $version,
        ];

        $html = '';
        foreach ($rows as $key => $value) {
            $html .= '<tr><td>' . $e($key) . '</td><td>' . $e($value) . '</td></tr>';
        }

        return '<div class="idt-panel" data-idt-panel="timing"><table class="idt-kv">' . $html . '</table></div>';
    }

    /**
     * The full default-connection query log, or null when capturing is off /
     * unavailable (no db binding, no connection) — the queries panel renders a
     * hint in that case rather than a count.
     *
     * @return array<int, array{query: string, bindings?: array<mixed>, time?: float|null}>|null
     */
    private function queryLog(): ?array
    {
        try {
            if (!config('database.query_log', false)) {
                return null;
            }

            $container = \Ions\Foundation\Kernel::app();
            if (!$container->bound('db')) {
                return null;
            }

            /** @var array<int, array{query: string, bindings?: array<mixed>, time?: float|null}> $log */
            $log = $container->get('db')->getConnection()->getQueryLog();

            return $log;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Strip summary for the query segment: 'log off' when capturing is
     * disabled/unavailable, else 'N (T ms)'.
     *
     * @param array<int, array{query: string, bindings?: array<mixed>, time?: float|null}>|null $log
     */
    private function queriesSummary(?array $log): string
    {
        if ($log === null) {
            return 'log off';
        }

        $total = 0.0;
        foreach ($log as $entry) {
            $total += (float) ($entry['time'] ?? 0);
        }

        return sprintf('%d (%.1f ms)', count($log), $total);
    }

    /**
     * The scoped stylesheet for the toolbar. Every rule is nested under
     * `#ions-debug-toolbar` so it cannot restyle the host page. The colour
     * values mirror {@see DesignSystem} tokens (--ion-surface #16181d, -text
     * #d8dce3, -muted #9aa4b2, -accent #818cf8, -border #2a2e36, -code-bg
     * #101218, -danger #e5534b, -vendor #6b7280) but are declared as plain
     * values inline so the bar looks right even on a page that never inlined
     * DesignSystem (a hard self-containment requirement).
     */
    private function styles(): string
    {
        return <<<'CSS'
            #ions-debug-toolbar{position:fixed;left:0;right:0;bottom:0;z-index:99999;
              font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#9aa4b2;}
            #ions-debug-toolbar *{box-sizing:border-box;}
            #ions-debug-toolbar .idt-strip{display:flex;gap:1rem;align-items:center;
              padding:.35rem .75rem;background:#16181d;border-top:1px solid #2a2e36;}
            #ions-debug-toolbar .idt-seg{background:none;border:0;color:#9aa4b2;cursor:pointer;
              font:inherit;padding:.1rem .2rem;border-radius:4px;white-space:nowrap;}
            #ions-debug-toolbar .idt-seg:hover,#ions-debug-toolbar .idt-seg.idt-active{
              color:#d8dce3;background:#22252c;}
            #ions-debug-toolbar .idt-brand{color:#818cf8;font-weight:700;}
            #ions-debug-toolbar .idt-close{margin-left:auto;background:none;border:0;
              color:#9aa4b2;cursor:pointer;font-size:14px;line-height:1;}
            #ions-debug-toolbar .idt-close:hover{color:#e5534b;}
            #ions-debug-toolbar .idt-panels{}
            #ions-debug-toolbar .idt-panel{display:none;max-height:40vh;overflow:auto;
              background:#101218;border-top:1px solid #2a2e36;padding:.5rem .75rem;color:#d8dce3;}
            #ions-debug-toolbar .idt-panel.idt-open{display:block;}
            #ions-debug-toolbar .idt-hint{margin:.25rem 0;color:#9aa4b2;font-style:italic;}
            #ions-debug-toolbar .idt-kv{border-collapse:collapse;width:100%;}
            #ions-debug-toolbar .idt-kv td{padding:.25rem .6rem;border-bottom:1px solid #2a2e36;
              vertical-align:top;overflow-wrap:anywhere;}
            #ions-debug-toolbar .idt-kv td:first-child{color:#9aa4b2;width:12rem;}
            #ions-debug-toolbar .idt-q-table{border-collapse:collapse;width:100%;}
            #ions-debug-toolbar .idt-q-table td{padding:.3rem .6rem;border-bottom:1px solid #2a2e36;
              vertical-align:top;}
            #ions-debug-toolbar .idt-q-n{color:#6b7280;text-align:right;width:2rem;user-select:none;}
            #ions-debug-toolbar .idt-q-sql code{color:#d8dce3;white-space:pre-wrap;word-break:break-word;
              font:inherit;display:block;}
            #ions-debug-toolbar .idt-q-meta{display:block;margin-top:.15rem;color:#6b7280;}
            #ions-debug-toolbar .idt-q-t{color:#818cf8;text-align:right;white-space:nowrap;width:6rem;}
            CSS;
    }

    /**
     * The ephemeral expand/collapse toggle. Clicking a segment opens its panel
     * (and closes any other); clicking the active segment closes it. No
     * persistence. Scoped to `#ions-debug-toolbar` via getElementById.
     */
    private function script(): string
    {
        return <<<'JS'
            (function(){var bar=document.getElementById('ions-debug-toolbar');if(!bar)return;
            bar.addEventListener('click',function(ev){
              var seg=ev.target.closest('[data-idt-toggle]');if(!seg||!bar.contains(seg))return;
              var name=seg.getAttribute('data-idt-toggle');
              var panel=bar.querySelector('[data-idt-panel="'+name+'"]');if(!panel)return;
              var willOpen=!panel.classList.contains('idt-open');
              bar.querySelectorAll('.idt-panel').forEach(function(p){p.classList.remove('idt-open');});
              bar.querySelectorAll('.idt-seg').forEach(function(s){s.classList.remove('idt-active');});
              if(willOpen){panel.classList.add('idt-open');
                bar.querySelectorAll('[data-idt-toggle="'+name+'"]').forEach(function(s){s.classList.add('idt-active');});}
            });})();
            JS;
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
