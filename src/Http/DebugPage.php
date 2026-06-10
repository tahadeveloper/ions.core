<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Bundles\Path;
use Ions\Bundles\RedactionProcessor;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Self-contained debug error page (APP_DEBUG only — production rendering in
 * {@see ExceptionHandler} never touches this class).
 *
 * One HTML document, inline CSS, no JavaScript, no external assets:
 *  - exception class + message + status header
 *  - source excerpt around the throwing line (escaped, error line highlighted)
 *  - getPrevious() chain (capped)
 *  - stack trace with host-relative paths, vendor frames de-emphasized
 *  - request summary (method/path/route/IP, headers and params) with the same
 *    recursive key redaction as {@see RedactionProcessor} plus Cookie and
 *    php-auth-* headers
 *
 * Deliberately NOT rendered, even in debug mode: env vars, config values,
 * server superglobals, frame arguments. Less surface, less leak.
 *
 * Failure safety: every section is wrapped so a broken section degrades to a
 * minimal fallback instead of replacing the error page with a new error.
 */
final class DebugPage
{
    private const MAX_CHAIN = 5;

    private const MAX_FRAMES = 50;

    private const EXCERPT_RADIUS = 10;

    private const MAX_LINE_LENGTH = 500;

    private const MAX_VALUE_LENGTH = 200;

    public function render(Throwable $e, Request $request, int $status): string
    {
        $title = $this->section(fn (): string => $this->e($e::class) . ' — ' . $status, fn (): string => 'Error');

        $sections = $this->section(fn (): string => $this->header($e, $status), fn (): string => '<h1>Error</h1>')
            . $this->section(fn (): string => $this->sourceExcerpt($e))
            . $this->section(fn (): string => $this->previousChain($e))
            . $this->section(
                fn (): string => $this->trace($e),
                fn (): string => '<pre>' . $this->e($e->getTraceAsString()) . '</pre>',
            )
            . $this->section(fn (): string => $this->requestSummary($request));

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>' . $this->css() . '</style>'
            . '</head><body><main>' . $sections . '</main></body></html>';
    }

    /**
     * Run one section renderer; if it throws for ANY reason, fall back to the
     * given minimal markup. The fallback is a callable so it is only built
     * when needed and is itself guarded — a broken error page is worse than
     * an ugly one.
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

        return '<header>'
            . '<p class="badge">' . $status . ' ' . $this->e($statusText) . '</p>'
            . '<p class="exc-class">' . $this->e($e::class) . '</p>'
            . '<h1>' . $this->e($e->getMessage() !== '' ? $e->getMessage() : $statusText) . '</h1>'
            . '</header>';
    }

    /**
     * ±10 lines around the throwing line, escaped and line-numbered, with the
     * offending line highlighted. Deliberately no syntax colouring: a correct,
     * always-escaped excerpt beats a fragile highlight_string() of a partial file.
     */
    private function sourceExcerpt(Throwable $e): string
    {
        $file = $e->getFile();
        $line = $e->getLine();

        $excerpt = $this->excerptHtml($file, $line);
        if ($excerpt === '') {
            return '';
        }

        return '<section><h2>Source</h2>'
            . '<p class="loc">' . $this->e($this->shortPath($file)) . ':' . $line . '</p>'
            . $excerpt
            . '</section>';
    }

    /** Build the <pre> excerpt for a file:line, or '' when unavailable. */
    private function excerptHtml(string $file, int $line): string
    {
        if ($line < 1 || !is_file($file) || !is_readable($file)) {
            return '';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === [] || $line > count($lines)) {
            return '';
        }

        $first = max(1, $line - self::EXCERPT_RADIUS);
        $last = min(count($lines), $line + self::EXCERPT_RADIUS);
        $width = strlen((string) $last);

        $out = '';
        for ($n = $first; $n <= $last; $n++) {
            $code = $lines[$n - 1];
            if (strlen($code) > self::MAX_LINE_LENGTH) {
                $code = substr($code, 0, self::MAX_LINE_LENGTH) . '…';
            }
            $row = str_pad((string) $n, $width, ' ', STR_PAD_LEFT) . '  ' . $this->e($code);
            $out .= $n === $line
                ? '<span class="line-err">' . $row . '</span>' . "\n"
                : $row . "\n";
        }

        return '<pre class="excerpt">' . $out . '</pre>';
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

    private function trace(Throwable $e): string
    {
        $rows = '';
        $i = 0;

        // Frame 0 is the throw site itself — getTrace() starts at the caller.
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '{throw}']];
        $frames = array_merge($frames, $e->getTrace());

        foreach ($frames as $frame) {
            if ($i >= self::MAX_FRAMES) {
                $rows .= '<tr><td colspan="3" class="vendor">… ' . (count($frames) - $i) . ' more frames</td></tr>';
                break;
            }

            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';

            $isVendor = $file !== '' && str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
            $location = $file !== '' ? $this->shortPath($file) . ($line > 0 ? ':' . $line : '') : '[internal]';

            $rows .= '<tr' . ($isVendor ? ' class="vendor"' : '') . '>'
                . '<td class="num">#' . $i . '</td>'
                . '<td>' . $this->e($class . $type . $function) . '</td>'
                . '<td class="loc">' . $this->e($location) . '</td>'
                . '</tr>';
            $i++;
        }

        return '<section><h2>Stack trace</h2><table class="trace">' . $rows . '</table></section>';
    }

    private function requestSummary(Request $request): string
    {
        // Method + path only — the query string may carry secrets, so query
        // params are shown exclusively through the redacting table below.
        $rows = '<tr><td>Method</td><td>' . $this->e($request->getMethod()) . '</td></tr>'
            . '<tr><td>Path</td><td>' . $this->e($request->getPathInfo()) . '</td></tr>';

        $route = $request->attributes->get('_route');
        if (is_string($route) && $route !== '') {
            $rows .= '<tr><td>Route</td><td>' . $this->e($route) . '</td></tr>';
        }

        $ip = $request->getClientIp();
        if (is_string($ip) && $ip !== '') {
            $rows .= '<tr><td>IP</td><td>' . $this->e($ip) . '</td></tr>';
        }

        $html = '<section><h2>Request</h2><table class="kv">' . $rows . '</table>';

        $html .= $this->section(fn (): string => $this->keyValueTable('Headers', $this->flattenHeaders($request)));
        $html .= $this->section(fn (): string => $this->keyValueTable('Query', $request->query->all()));
        $html .= $this->section(fn (): string => $this->keyValueTable('Body', $request->request->all()));

        return $html . '</section>';
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

    /**
     * Render a key/value table with redaction (sensitive keys masked) and
     * value truncation. Empty data renders nothing.
     *
     * @param array<array-key, mixed> $data
     */
    private function keyValueTable(string $caption, array $data): string
    {
        if ($data === []) {
            return '';
        }

        // Recursive: nested params (e.g. user[password]) must be masked
        // before stringify() json_encodes array values, or they leak raw.
        $data = RedactionProcessor::redact($data, $this->isSensitiveKey(...));

        $rows = '';
        foreach ($data as $key => $value) {
            $rows .= '<tr><td>' . $this->e((string) $key) . '</td><td>'
                . $this->e($this->stringify($value))
                . '</td></tr>';
        }

        return '<h3>' . $this->e($caption) . '</h3><table class="kv">' . $rows . '</table>';
    }

    /**
     * Same key patterns as log redaction, plus Cookie/Set-Cookie and the
     * php-auth-* headers Symfony's ServerBag decodes Basic credentials into:
     * the debug page must never print raw Authorization, Cookie or decoded
     * Basic-auth values.
     */
    private function isSensitiveKey(string $key): bool
    {
        return RedactionProcessor::isSensitiveKey($key) || preg_match('/cookie|^php-auth/i', $key) === 1;
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

    private function css(): string
    {
        return <<<'CSS'
            :root{color-scheme:dark light}
            body{margin:0;background:#16181d;color:#d8dce3;font:15px/1.5 ui-sans-serif,system-ui,sans-serif}
            main{max-width:60rem;margin:0 auto;padding:1.5rem}
            header{border-left:4px solid #e5534b;padding:.25rem 1rem;margin-bottom:1.5rem}
            h1{margin:.25rem 0;font-size:1.4rem;color:#fff;overflow-wrap:anywhere}
            h2{font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:#8b939f;margin:1.75rem 0 .5rem}
            h3{font-size:.85rem;color:#8b939f;margin:1rem 0 .25rem}
            .badge{display:inline-block;background:#e5534b;color:#fff;border-radius:4px;padding:.1rem .5rem;font-weight:600;margin:0}
            .exc-class{font-family:ui-monospace,monospace;color:#9aa4b1;margin:.5rem 0 0}
            .loc{font-family:ui-monospace,monospace;color:#7ea6e0;overflow-wrap:anywhere}
            pre.excerpt{background:#0e1013;border:1px solid #2a2e36;border-radius:6px;padding:.75rem 0;overflow-x:auto;font:13px/1.55 ui-monospace,monospace}
            pre.excerpt,table.trace td,table.kv td{font-variant-ligatures:none}
            .line-err{display:inline-block;width:100%;background:#5c2624;color:#ffd9d6}
            pre.excerpt span,pre.excerpt{white-space:pre}
            table{border-collapse:collapse;width:100%}
            table.trace td,table.kv td{padding:.25rem .6rem;border-bottom:1px solid #232730;font:13px/1.5 ui-monospace,monospace;vertical-align:top;overflow-wrap:anywhere}
            table.kv td:first-child{color:#8b939f;width:14rem}
            td.num{color:#8b939f;width:3rem}
            tr.vendor,td.vendor{opacity:.45}
            CSS;
    }
}
