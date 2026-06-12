<?php

declare(strict_types=1);

namespace Ions\Http\Ui;

use Throwable;

/**
 * Dependency-free, server-side PHP syntax highlighter (Phase 14.1).
 *
 * Uses PHP's own {@see token_get_all()} — zero JS library, works with
 * JavaScript disabled — to wrap each token in a `<span class="tok-{kind}">`
 * carrying the {@see DesignSystem} colour for that family. Token text is
 * HTML-escaped *before* it is wrapped, so an XSS payload sitting in source
 * code can never execute when the highlighted source is displayed.
 *
 * Fail-safe by construction: any tokenisation failure (malformed/partial PHP,
 * a warning-raising call) degrades to a plainly-escaped string rather than
 * throwing — a debug surface that crashes while rendering source is worse than
 * an unhighlighted one. {@see excerpt()} reads a file and returns '' (never
 * throws) when it is unreadable.
 *
 * Line model: tokens whose text spans multiple physical lines (multi-line
 * strings, heredocs, block/doc comments) are split on "\n" and EACH line's
 * segment is wrapped in its own `<span>`, so a span never crosses a newline.
 * {@see excerpt()} can therefore prepend its line-number gutter outside any
 * open token span and the emitted HTML stays balanced. ({@see highlightLines()}
 * is the shared primitive; {@see highlight()} simply joins it with "\n".)
 *
 * @see DesignSystem for the .tok-* colour rules.
 */
final class SourceHighlighter
{
    private const MAX_LINE_LENGTH = 500;

    /** PHP token names treated as keywords (highlighted as `.tok-keyword`). */
    private const KEYWORD_TOKENS = [
        T_ABSTRACT, T_AS, T_BREAK, T_CALLABLE, T_CASE, T_CATCH, T_CLASS,
        T_CLONE, T_CONST, T_CONTINUE, T_DECLARE, T_DEFAULT, T_DO, T_ECHO,
        T_ELSE, T_ELSEIF, T_EMPTY, T_ENDDECLARE, T_ENDFOR, T_ENDFOREACH,
        T_ENDIF, T_ENDSWITCH, T_ENDWHILE, T_ENUM, T_EXTENDS, T_FINAL,
        T_FINALLY, T_FN, T_FOR, T_FOREACH, T_FUNCTION, T_GLOBAL, T_GOTO,
        T_IF, T_IMPLEMENTS, T_INCLUDE, T_INCLUDE_ONCE, T_INSTANCEOF,
        T_INSTEADOF, T_INTERFACE, T_ISSET, T_LIST, T_MATCH, T_NAMESPACE,
        T_NEW, T_PRINT, T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY,
        T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_STATIC, T_SWITCH, T_THROW,
        T_TRAIT, T_TRY, T_UNSET, T_USE, T_VAR, T_WHILE, T_YIELD,
        T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
    ];

    /**
     * Highlight a PHP source string into HTML with `<span class="tok-*">`
     * tokens. Always returns HTML-escaped, markup-safe output; on any failure
     * returns the whole input plainly escaped.
     */
    public static function highlight(string $php): string
    {
        return implode("\n", self::highlightLines($php));
    }

    /**
     * Highlight $php into an array of HTML lines, one entry per physical source
     * line, each a balanced sequence of complete `<span>` tokens (no span
     * crosses a newline). On any tokenisation failure each line degrades to a
     * plainly-escaped string. The shared primitive behind {@see highlight()}
     * and {@see excerpt()}.
     *
     * @return list<string>
     */
    private static function highlightLines(string $php): array
    {
        try {
            /** @var array<int, array{0:int,1:string,2:int}|string> $tokens */
            $tokens = @token_get_all($php, TOKEN_PARSE);
        } catch (Throwable) {
            try {
                /** @var array<int, array{0:int,1:string,2:int}|string> $tokens */
                $tokens = @token_get_all($php);
            } catch (Throwable) {
                return array_map(static fn (string $line): string => self::e($line), explode("\n", $php));
            }
        }

        $lines = [''];
        foreach ($tokens as $token) {
            $class = is_array($token) ? self::kind($token[0]) : 'default';
            $text  = is_array($token) ? $token[1] : $token;

            // Split a (possibly multi-line) token's text so each physical line
            // gets its own span — a span must never straddle a "\n".
            $segments = explode("\n", $text);
            foreach ($segments as $i => $segment) {
                if ($i > 0) {
                    $lines[] = '';
                }
                if ($segment !== '') {
                    $lines[count($lines) - 1] .= '<span class="tok-' . $class . '">' . self::e($segment) . '</span>';
                }
            }
        }

        return $lines;
    }

    /**
     * Read $file and render the window $errorLine ± $pad as a highlighted
     * `<pre class="ion-code">` with line numbers, the error line wrapped in
     * `.line-err`. Returns '' (never throws) when the file is unreadable or the
     * line is out of range.
     */
    public static function excerpt(string $file, int $errorLine, int $pad = 6): string
    {
        if ($errorLine < 1 || !is_file($file) || !is_readable($file)) {
            return '';
        }

        $source = @file_get_contents($file);
        if ($source === false || $source === '') {
            return '';
        }

        // Per-line highlighted HTML — each entry is a balanced run of spans, so
        // the line-number gutter below is injected outside any token span.
        $lines = self::highlightLines($source);
        $count = count($lines);
        if ($errorLine > $count) {
            return '';
        }

        $first = max(1, $errorLine - $pad);
        $last = min($count, $errorLine + $pad);
        $width = strlen((string) $last);

        $body = '';
        for ($n = $first; $n <= $last; $n++) {
            $code = $lines[$n - 1];
            if (strlen($code) > self::MAX_LINE_LENGTH) {
                $code = substr($code, 0, self::MAX_LINE_LENGTH) . '…';
            }

            $row = '<span class="ln">' . str_pad((string) $n, $width, ' ', STR_PAD_LEFT) . '</span>' . $code;
            $body .= $n === $errorLine
                ? '<span class="line-err">' . $row . '</span>' . "\n"
                : $row . "\n";
        }

        return '<pre class="ion-code">' . $body . '</pre>';
    }

    /** Map a PHP token id to a `.tok-*` family suffix. */
    private static function kind(int $id): string
    {
        return match (true) {
            $id === T_COMMENT, $id === T_DOC_COMMENT => 'comment',
            $id === T_CONSTANT_ENCAPSED_STRING, $id === T_ENCAPSED_AND_WHITESPACE => 'string',
            $id === T_LNUMBER, $id === T_DNUMBER => 'number',
            $id === T_VARIABLE => 'variable',
            in_array($id, self::KEYWORD_TOKENS, true) => 'keyword',
            default => 'default',
        };
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
