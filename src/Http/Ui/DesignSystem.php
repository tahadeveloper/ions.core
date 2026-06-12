<?php

declare(strict_types=1);

namespace Ions\Http\Ui;

/**
 * The single source of the framework's visual identity (Phase 14.1).
 *
 * Every first-party HTML surface — the debug page, the debug toolbar, the
 * branded production error pages and the start page — inlines {@see css()} so
 * the look stays consistent and self-contained (no external CSS/asset
 * pipeline, a hard requirement for pages that may render *because* the
 * pipeline broke).
 *
 * The palette is distilled from the previously ad-hoc inline styles
 * (DebugPage's dark surface, the toolbar's #16181d / #7aa2f7, the error/start
 * pages' #101218 / #818cf8) into one dark-default, indigo-accent token set,
 * exposed as CSS custom properties so surfaces compose primitives instead of
 * re-declaring colours.
 *
 * `css()` returns the `:root` token block PLUS the shared primitive rules
 * (badge, code block, key/value table, link, the error-line highlight and the
 * `.tok-*` syntax classes {@see SourceHighlighter} emits). `tokensOnly()`
 * returns just the token block for surfaces that bring their own layout.
 *
 * Everything is one inert string: no `</style>`-breaking sequence, no
 * interpolation, no I/O.
 */
final class DesignSystem
{
    /**
     * The `:root` custom-property block: colour tokens, a spacing scale and the
     * sans/mono font stacks, with `color-scheme: dark light` so form controls
     * and scrollbars follow the dark default.
     */
    public static function tokensOnly(): string
    {
        return <<<'CSS'
            :root{
              color-scheme: dark light;
              --ion-bg: #101218;
              --ion-surface: #16181d;
              --ion-text: #d8dce3;
              --ion-muted: #9aa4b2;
              --ion-accent: #818cf8;
              --ion-danger: #e5534b;
              --ion-vendor: #6b7280;
              --ion-border: #2a2e36;
              --ion-code-bg: #0e1013;
              --ion-space-1: .25rem;
              --ion-space-2: .5rem;
              --ion-space-3: .75rem;
              --ion-space-4: 1rem;
              --ion-space-6: 1.5rem;
              --ion-font-sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
              --ion-font-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }
            CSS;
    }

    /**
     * The full shared stylesheet: the token block plus the primitive rules
     * (`.ion-badge`, `pre.ion-code`, `table.ion-kv`, `a.ion-link`, `.line-err`
     * and the `.tok-*` syntax classes) every surface reuses.
     */
    public static function css(): string
    {
        return self::tokensOnly() . "\n" . <<<'CSS'
            .ion-badge{
              display:inline-block;background:var(--ion-danger);color:#fff;
              border-radius:4px;padding:.1rem .5rem;font-weight:600;
              font-family:var(--ion-font-sans);font-size:.85rem;
            }
            pre.ion-code{
              background:var(--ion-code-bg);border:1px solid var(--ion-border);
              border-radius:6px;padding:.75rem 0;margin:0;overflow-x:auto;
              font:13px/1.55 var(--ion-font-mono);font-variant-ligatures:none;
              white-space:pre;color:var(--ion-text);
            }
            pre.ion-code .ln{
              display:inline-block;width:3rem;padding-right:.75rem;text-align:right;
              color:var(--ion-vendor);user-select:none;
            }
            .line-err{
              display:inline-block;width:100%;background:#5c2624;color:#ffd9d6;
            }
            table.ion-kv{border-collapse:collapse;width:100%}
            table.ion-kv td{
              padding:.25rem .6rem;border-bottom:1px solid var(--ion-border);
              font:13px/1.5 var(--ion-font-mono);vertical-align:top;
              overflow-wrap:anywhere;color:var(--ion-text);
            }
            table.ion-kv td:first-child{color:var(--ion-muted);width:14rem}
            a.ion-link{color:var(--ion-accent);text-decoration:none}
            a.ion-link:hover{text-decoration:underline}
            .tok-keyword{color:#c792ea}
            .tok-string{color:#9ece6a}
            .tok-comment{color:#6b7280;font-style:italic}
            .tok-number{color:#ff9e64}
            .tok-variable{color:#7aa2f7}
            .tok-default{color:var(--ion-text)}
            CSS;
    }
}
