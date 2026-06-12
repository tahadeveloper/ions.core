<?php

declare(strict_types=1);

namespace Ions\Http;

use Composer\InstalledVersions;
use Ions\Bundles\Path;
use Ions\Bundles\RedactionProcessor;
use Ions\Http\Ui\DesignSystem;
use Ions\Http\Ui\EditorLink;
use Ions\Http\Ui\SourceHighlighter;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Self-contained, interactive debug error page (APP_DEBUG only — production
 * rendering in {@see ExceptionHandler} never touches this class).
 *
 * Phase 14.2: a Whoops/Ignition-class experience built on the 14.1 UI layer
 * ({@see DesignSystem}, {@see SourceHighlighter}, {@see EditorLink}) — clickable
 * stack frames swapping a syntax-highlighted source pane, tabs for the request /
 * headers / params / cookies / session / context, a copy-as-markdown action and
 * open-in-editor links — while keeping the previous page's two hard guarantees:
 *
 *  - **Redaction.** Every value displayed (headers, query, body, cookies AND
 *    session) runs through the same recursive key redaction as
 *    {@see RedactionProcessor} plus Cookie / php-auth-* — secrets are masked.
 *  - **Fail-closed.** Every block is wrapped by {@see section()}; a failure in
 *    any one block degrades that block, it never throws out of {@see render()}.
 *
 * One HTML document; CSS and JS are inlined ({@see DesignSystem::css()} + a tiny
 * vanilla script) — no `<link>` / `<script src>`, ever: an error page must not
 * depend on an asset pipeline that may itself be the failure. The page is fully
 * readable with JavaScript disabled (the throw-frame excerpt and the whole trace
 * are rendered server-side; JS only swaps panes and tabs).
 *
 * Deliberately NOT rendered, even in debug mode: env vars, config values,
 * server superglobals, frame arguments. Less surface, less leak.
 */
final class DebugPage
{
    private const MAX_CHAIN = 5;

    private const MAX_FRAMES = 50;

    private const EXCERPT_PAD = 8;

    private const MAX_VALUE_LENGTH = 200;

    public function render(Throwable $e, Request $request, int $status): string
    {
        $frames = $this->frames($e);
        $selected = 0;

        $title = $this->section(
            fn (): string => $this->e($e::class) . ' — ' . $status,
            fn (): string => 'Error',
        );

        $body = $this->section(fn (): string => $this->header($e, $status), fn (): string => '<h1>Error</h1>')
            . $this->section(fn (): string => $this->panes($frames, $selected))
            . $this->section(fn (): string => $this->previousChain($e))
            . $this->section(fn (): string => $this->tabs($request, $e));

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>' . DesignSystem::css() . "\n" . $this->layoutCss() . '</style>'
            . '</head><body><main class="ion-debug">' . $body . '</main>'
            . '<script>' . $this->js() . '</script>'
            . '</body></html>';
    }

    /**
     * Run one section renderer; if it throws for ANY reason, fall back to the
     * given minimal markup. The fallback is a callable so it is only built when
     * needed and is itself guarded — a broken error page is worse than an ugly
     * one. KEEP THIS: every block in render() goes through it.
     */
    private function section(callable $renderer, ?callable $fallback = null): string
    {
        try {
            return (string) $renderer();
        } catch (Throwable) {
            try {
                return $fallback === null ? '' : (string) $fallback();
            } catch (Throwable) {
                return '';
            }
        }
    }

    private function header(Throwable $e, int $status): string
    {
        $statusText = Response::$statusTexts[$status] ?? 'Error';
        $message = $e->getMessage() !== '' ? $e->getMessage() : $statusText;

        $loc = $this->e($this->shortPath($e->getFile())) . ':' . $e->getLine();
        $editor = $this->section(fn (): string => $this->editorLink($e->getFile(), $e->getLine(), 'open'));

        $copyMd = $this->markdown($e, $status);

        return '<header>'
            . '<div class="hd-top">'
            . '<span class="ion-badge">' . $status . ' ' . $this->e($statusText) . '</span>'
            . '<button type="button" class="btn-copy" data-copy="' . $this->e($copyMd) . '">Copy as Markdown</button>'
            . '</div>'
            . '<p class="exc-class">' . $this->e($e::class) . '</p>'
            . '<h1>' . $this->e($message) . '</h1>'
            . '<p class="loc">' . $loc . ' ' . $editor . '</p>'
            . '</header>';
    }

    /**
     * The throw frame followed by getTrace(), normalised + capped, each a
     * {file,line,label,vendor} record. Frame 0 is the throw site itself —
     * getTrace() starts at the caller.
     *
     * @return list<array{file:string,line:int,label:string,vendor:bool,truncated?:int}>
     */
    private function frames(Throwable $e): array
    {
        $raw = array_merge(
            [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '{throw}']],
            $e->getTrace(),
        );

        $frames = [];
        $i = 0;
        foreach ($raw as $frame) {
            if ($i >= self::MAX_FRAMES) {
                $frames[] = ['file' => '', 'line' => 0, 'label' => '', 'vendor' => true, 'truncated' => count($raw) - $i];
                break;
            }

            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';

            $frames[] = [
                'file' => $file,
                'line' => $line,
                'label' => $class . $type . $function,
                'vendor' => $file !== '' && str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR),
            ];
            $i++;
        }

        return $frames;
    }

    /**
     * The two-pane body: LEFT the clickable frame list, RIGHT one highlighted
     * source pane per frame ({@see SourceHighlighter::excerpt}). All panes are
     * rendered server-side; the selected one is visible and the rest carry
     * `hidden`, which the inline JS toggles. With JS off the throw-frame excerpt
     * is shown and the full frame list stays readable.
     *
     * @param list<array{file:string,line:int,label:string,vendor:bool,truncated?:int}> $frames
     */
    private function panes(array $frames, int $selected): string
    {
        $list = '';
        $source = '';

        foreach ($frames as $n => $frame) {
            if (isset($frame['truncated'])) {
                $list .= '<li class="frame vendor more">… ' . $frame['truncated'] . ' more frames</li>';
                continue;
            }

            $location = $frame['file'] !== ''
                ? $this->shortPath($frame['file']) . ($frame['line'] > 0 ? ':' . $frame['line'] : '')
                : '[internal]';
            $isSel = $n === $selected;
            $editor = $frame['file'] !== '' && $frame['line'] > 0
                ? $this->section(fn (): string => $this->editorLink($frame['file'], $frame['line'], '↗'))
                : '';

            $list .= '<li class="frame' . ($frame['vendor'] ? ' vendor' : '') . ($isSel ? ' active' : '') . '"'
                . ' data-frame="' . $n . '" tabindex="0">'
                . '<span class="fn">' . $this->e($frame['label']) . '</span>'
                . '<span class="loc">' . $this->e($location) . ' ' . $editor . '</span>'
                . '</li>';

            // Skip excerpt work for unreadable files (dev-only page, but bounded).
            $excerpt = $this->section(
                fn (): string => SourceHighlighter::excerpt($frame['file'], $frame['line'], self::EXCERPT_PAD),
            );
            if ($excerpt === '') {
                $excerpt = '<pre class="ion-code">' . $this->e($location) . '</pre>';
            }

            $source .= '<div class="pane" data-pane="' . $n . '"' . ($isSel ? '' : ' hidden') . '>'
                . '<p class="loc">' . $this->e($location) . '</p>' . $excerpt . '</div>';
        }

        return '<section class="body">'
            . '<ol class="frames">' . $list . '</ol>'
            . '<div class="source">' . $source . '</div>'
            . '</section>';
    }

    /** Render the getPrevious() chain (class + message + file:line each). */
    private function previousChain(Throwable $e): string
    {
        $items = '';
        $seen = [spl_object_id($e) => true];
        $prev = $e->getPrevious();
        $depth = 0;

        while ($prev !== null && $depth < self::MAX_CHAIN && !isset($seen[spl_object_id($prev)])) {
            $seen[spl_object_id($prev)] = true;
            $items .= '<li><span class="exc-class">' . $this->e($prev::class) . '</span>: '
                . $this->e($prev->getMessage())
                . ' <span class="loc">' . $this->e($this->shortPath($prev->getFile())) . ':' . $prev->getLine() . '</span>'
                . '</li>';
            $prev = $prev->getPrevious();
            $depth++;
        }

        if ($items === '') {
            return '';
        }

        return '<section><h2>Caused by</h2><ol class="chain">' . $items . '</ol></section>';
    }

    /**
     * The redacted detail tabs. EVERY panel value goes through the same
     * {@see redactedTable()} redactor — including the new Cookies and Session
     * panels (the security review's #1 focus). With JS off all panels stay
     * visible (stacked); JS reveals one at a time.
     */
    private function tabs(Request $request, Throwable $e): string
    {
        /** @var array<string, callable(): string> $panels */
        $panels = [
            'Request' => fn (): string => $this->requestPanel($request),
            'Headers' => fn (): string => $this->redactedTable($this->flattenHeaders($request)),
            'Params' => fn (): string => $this->redactedTable($request->query->all())
                . $this->redactedTable($request->request->all()),
            'Cookies' => fn (): string => $this->redactedTable($this->cookies($request), maskAllValues: true),
            'Session' => fn (): string => $this->redactedTable($this->sessionValues($request)),
            'Context' => fn (): string => $this->redactedTable($this->context($e)),
        ];

        $nav = '';
        $bodies = '';
        $i = 0;
        foreach ($panels as $label => $renderer) {
            $active = $i === 0;
            $nav .= '<button type="button" class="tab' . ($active ? ' active' : '') . '" data-tab="' . $i . '">'
                . $this->e($label) . '</button>';
            $content = $this->section($renderer);
            if ($content === '') {
                $content = '<p class="empty">—</p>';
            }
            $bodies .= '<div class="tab-panel" data-panel="' . $i . '"' . ($active ? '' : ' hidden') . '>'
                . '<h3>' . $this->e($label) . '</h3>' . $content . '</div>';
            $i++;
        }

        return '<section class="tabs"><nav class="tabbar">' . $nav . '</nav>' . $bodies . '</section>';
    }

    private function requestPanel(Request $request): string
    {
        $rows = [
            'Method' => $request->getMethod(),
            'Path' => $request->getPathInfo(),
        ];

        $route = $request->attributes->get('_route');
        if (is_string($route) && $route !== '') {
            $rows['Route'] = $route;
        }

        $rows['Status'] = (string) ($request->attributes->get('_ion_status') ?? '');
        $ip = $request->getClientIp();
        if (is_string($ip) && $ip !== '') {
            $rows['IP'] = $ip;
        }

        // No redaction needed here (method/path/route/ip), but route through the
        // same renderer for layout consistency; values are plain scalars.
        return $this->redactedTable(array_filter($rows, static fn (string $v): bool => $v !== ''));
    }

    /** @return array<string, mixed> */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_map(strval(...), $values));
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function cookies(Request $request): array
    {
        $out = [];
        foreach ($request->cookies->all() as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * Session values, or [] when the request has no started session. Like every
     * other tab these go through {@see redactedTable()} — never raw.
     *
     * @return array<array-key, mixed>
     */
    private function sessionValues(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        try {
            $session = $request->getSession();

            return $session->isStarted() || $session->all() !== [] ? $session->all() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function context(Throwable $e): array
    {
        return [
            'PHP version' => PHP_VERSION,
            'Ions version' => $this->ionsVersion(),
            'Environment' => (string) (config('app.env', '') ?? ''),
            'Peak memory' => sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576),
            'Exception code' => (string) $e->getCode(),
        ];
    }

    /**
     * Render a key/value table with redaction (sensitive keys masked) and value
     * truncation. Empty data renders nothing. THE single redacting renderer —
     * Headers, Query, Body, Cookies AND Session all flow through it, so the
     * sensitive-key set is applied uniformly.
     *
     * When $maskAllValues is true EVERY value is masked regardless of key name,
     * keeping keys visible. The Cookies tab uses this: cookies are overwhelmingly
     * credentials (session ids, remember-me, CSRF), so a non-sensitive cookie name
     * like "sid" must never expose its value. Other tabs stay key-based — session
     * values like auth_user_id are legitimately useful to a developer.
     *
     * @param array<array-key, mixed> $data
     */
    private function redactedTable(array $data, bool $maskAllValues = false): string
    {
        if ($data === []) {
            return '';
        }

        // Recursive: nested params (e.g. user[password]) must be masked before
        // stringify() json_encodes array values, or they leak raw.
        $predicate = $maskAllValues ? static fn (): bool => true : $this->isSensitiveKey(...);
        $data = RedactionProcessor::redact($data, $predicate);

        $rows = '';
        foreach ($data as $key => $value) {
            $rows .= '<tr><td>' . $this->e((string) $key) . '</td><td>'
                . $this->e($this->stringify($value))
                . '</td></tr>';
        }

        return '<table class="ion-kv">' . $rows . '</table>';
    }

    /**
     * Same key patterns as log redaction, plus Cookie/Set-Cookie and the
     * php-auth-* headers Symfony's ServerBag decodes Basic credentials into:
     * the debug page must never print raw Authorization, Cookie or decoded
     * Basic-auth values.
     */
    private function isSensitiveKey(string $key): bool
    {
        return RedactionProcessor::isSensitiveKey($key) || preg_match('/cookie|^php-auth|session|csrf/i', $key) === 1;
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            try {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (Throwable) {
                $value = '[unserializable]';
            }
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (!is_scalar($value)) {
            $value = $value === null ? '' : '[' . get_debug_type($value) . ']';
        }

        $value = (string) $value;

        return strlen($value) > self::MAX_VALUE_LENGTH
            ? substr($value, 0, self::MAX_VALUE_LENGTH) . '…'
            : $value;
    }

    /**
     * Build the copy-as-markdown block (class, message, location, trace). Plain
     * text destined for a `data-copy` attribute; {@see e()} escapes it for the
     * attribute and the JS reads the decoded value.
     */
    private function markdown(Throwable $e, int $status): string
    {
        $lines = [
            '**' . $e::class . '** (' . $status . ')',
            '',
            '> ' . $e->getMessage(),
            '',
            '`' . $this->shortPath($e->getFile()) . ':' . $e->getLine() . '`',
            '',
            '```',
            $e->getTraceAsString(),
            '```',
        ];

        return implode("\n", $lines);
    }

    /** Build an open-in-editor anchor, or '' when no editor is configured. */
    private function editorLink(string $file, int $line, string $label): string
    {
        $href = EditorLink::for($file, $line);
        if ($href === null) {
            return '';
        }

        return '<a class="ion-link edit" href="' . $this->e($href) . '">' . $this->e($label) . '</a>';
    }

    private function ionsVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('ionzile/core') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }

    /** Shorten an absolute path relative to the host base path when possible. */
    private function shortPath(string $path): string
    {
        try {
            $base = Path::root('');
            if ($base !== '' && str_starts_with($path, $base)) {
                return ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
            }
        } catch (Throwable) {
            // Path resolution must never break error rendering.
        }

        return $path;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Page-specific layout on top of {@see DesignSystem::css()}. */
    private function layoutCss(): string
    {
        return <<<'CSS'
            body{margin:0;background:var(--ion-bg);color:var(--ion-text);font:15px/1.5 var(--ion-font-sans)}
            main.ion-debug{max-width:72rem;margin:0 auto;padding:var(--ion-space-6)}
            header{border-left:4px solid var(--ion-danger);padding:.25rem 1rem;margin-bottom:var(--ion-space-6)}
            .hd-top{display:flex;align-items:center;justify-content:space-between;gap:1rem}
            h1{margin:.25rem 0;font-size:1.4rem;color:#fff;overflow-wrap:anywhere}
            h2{font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ion-muted);margin:1.75rem 0 .5rem}
            h3{font-size:.85rem;color:var(--ion-muted);margin:1rem 0 .5rem}
            .exc-class{font-family:var(--ion-font-mono);color:var(--ion-muted);margin:.5rem 0 0}
            .loc{font-family:var(--ion-font-mono);color:var(--ion-accent);overflow-wrap:anywhere;margin:.25rem 0}
            .btn-copy,.tab{font:inherit;cursor:pointer;background:var(--ion-surface);color:var(--ion-text);
              border:1px solid var(--ion-border);border-radius:6px;padding:.3rem .7rem;font-size:.85rem}
            .btn-copy:hover,.tab:hover{border-color:var(--ion-accent)}
            .tab.active{background:var(--ion-accent);color:#fff;border-color:var(--ion-accent)}
            .body{display:grid;grid-template-columns:22rem 1fr;gap:1rem;align-items:start}
            @media(max-width:60rem){.body{grid-template-columns:1fr}}
            ol.frames{list-style:none;margin:0;padding:0;max-height:32rem;overflow:auto;
              border:1px solid var(--ion-border);border-radius:6px;background:var(--ion-surface)}
            li.frame{padding:.4rem .6rem;border-bottom:1px solid var(--ion-border);cursor:pointer;display:block}
            li.frame:last-child{border-bottom:0}
            li.frame.active{background:var(--ion-code-bg);border-left:3px solid var(--ion-accent)}
            li.frame.vendor{opacity:.5}
            li.frame.more{cursor:default;font-style:italic}
            li.frame .fn{display:block;font-family:var(--ion-font-mono);font-size:.8rem;overflow-wrap:anywhere}
            li.frame .loc{display:block;font-size:.75rem;margin:.1rem 0 0}
            .source .pane .loc{margin:.25rem 0}
            a.ion-link.edit{font-size:.75rem;padding-left:.25rem}
            .tabs{margin-top:1.75rem}
            .tabbar{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.75rem}
            .tab-panel .empty{color:var(--ion-muted);font-family:var(--ion-font-mono)}
            ol.chain{list-style:none;margin:0;padding:0}
            ol.chain li{padding:.35rem 0;border-bottom:1px solid var(--ion-border);font-size:.9rem}
            CSS;
    }

    /**
     * The tiny vanilla enhancement script: frame switch (swap source pane), tab
     * switch (reveal one panel) and copy-as-markdown (clipboard with a textarea
     * fallback). Inert with JS off — every pane/panel is already server-rendered.
     */
    private function js(): string
    {
        return <<<'JS'
            (function(){
              var root=document;
              function show(sel,attr,n){
                root.querySelectorAll(sel).forEach(function(el){
                  el.hidden=el.getAttribute(attr)!==String(n);
                });
              }
              root.querySelectorAll('li.frame[data-frame]').forEach(function(li){
                li.addEventListener('click',function(){
                  var n=li.getAttribute('data-frame');
                  root.querySelectorAll('li.frame').forEach(function(f){f.classList.remove('active');});
                  li.classList.add('active');
                  show('.source .pane','data-pane',n);
                });
              });
              root.querySelectorAll('.tab[data-tab]').forEach(function(btn){
                btn.addEventListener('click',function(){
                  var n=btn.getAttribute('data-tab');
                  root.querySelectorAll('.tab').forEach(function(b){b.classList.remove('active');});
                  btn.classList.add('active');
                  show('.tab-panel','data-panel',n);
                });
              });
              var copy=root.querySelector('.btn-copy');
              if(copy){
                copy.addEventListener('click',function(){
                  var text=copy.getAttribute('data-copy')||'';
                  var done=function(){var t=copy.textContent;copy.textContent='Copied!';setTimeout(function(){copy.textContent=t;},1200);};
                  if(navigator.clipboard&&navigator.clipboard.writeText){
                    navigator.clipboard.writeText(text).then(done,function(){fallback(text,done);});
                  }else{fallback(text,done);}
                });
              }
              function fallback(text,done){
                var ta=document.createElement('textarea');
                ta.value=text;ta.style.position='fixed';ta.style.opacity='0';
                document.body.appendChild(ta);ta.select();
                try{document.execCommand('copy');done();}catch(e){}
                document.body.removeChild(ta);
              }
            })();
            JS;
    }
}
