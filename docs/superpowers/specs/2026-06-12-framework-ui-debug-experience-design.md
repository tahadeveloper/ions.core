# Phase 14 — Framework UI & Developer Experience Design

**Date:** 2026-06-12
**Status:** Approved (user: "second one, start all")

## Goal

Give Ions a cohesive, polished, first-party visual identity across its four
user-facing HTML surfaces — the debug error page, the debug toolbar, the
production error pages, and the start/welcome page — and turn the debug page
into a full **interactive, Whoops/Ignition-class** debugging experience, all
**dependency-free** (no Whoops, no JS framework, no external CSS/JS), and with
the existing **security posture preserved** (request redaction; dev-only
gating).

## Why (the problem)

Today these four surfaces were each styled ad-hoc with their own inline CSS and
have no shared identity. The debug page (`src/Http/DebugPage.php`) is competent
and security-conscious (it **redacts** secrets — a real edge over Whoops, which
shows raw request data, and Ignition, which shipped RCE CVE-2021-3129) but it is
**static**: no clickable stack frames, no syntax highlighting, no request/
session/cookie tabs, no copy/open-in-editor. The debug toolbar
(`DebugToolbarMiddleware`) is inline-styled and non-interactive (no expandable
panels). The production error pages are minimal. The result feels unfinished for
a framework.

We deliberately keep the "no Whoops/Ignition" decision (CLAUDE.md) and instead
**level up our own** page — gaining interactivity while keeping redaction and
zero dependencies.

## Non-negotiable constraints

1. **Self-contained inline assets.** The debug page and production error pages
   MUST inline their CSS (and the debug page its JS) directly into the emitted
   HTML. An error page must never depend on the asset pipeline, a CDN, or
   filesystem assets being functional — a broken pipeline may *be* the error.
   No `<link>`/`<script src>` to external files.
2. **Server-side syntax highlighting.** PHP source is highlighted with PHP's
   own `token_get_all()` into `<span class="tok-*">` — zero JS library, works
   with JS disabled. (JS only enhances interactivity: frame switching, tabs,
   copy.) The page is fully readable and navigable without JS as a fallback.
3. **Dev-only gating preserved.** The interactive debug page and the toolbar
   render ONLY when `APP_DEBUG` is truthy. Production error pages are tiny
   static HTML/CSS with **no JS**. JSON/API error paths are unchanged (never
   templated).
4. **Security: redaction stays.** The request/headers/cookies/session tabs use
   the SAME recursive key redaction as today's `DebugPage::redactedTable()` /
   `RedactionProcessor` (Authorization, Cookie, password, token, secret, …).
   Adding session + cookie views MUST run them through redaction too.
5. **Never break the response.** Every renderer stays fail-closed: any failure
   inside the error/debug/toolbar path is swallowed and degrades to a simpler
   page (the existing `DebugPage::section()` / toolbar try/catch precedent). A
   debug page that throws while rendering an exception is worse than a plain one.
6. **Zero new Composer dependencies.** PHP 8.3, no new packages. Keep CI green:
   pest (zero warnings), phpstan level 5 + level 8 (core, no baseline),
   php-cs-fixer, MySQL job. New PSR-4 code is `declare(strict_types=1)` and
   level-8 clean.

## Architecture

Rendering entry points are unchanged: `ExceptionHandler::render()` →
`html()` → (`APP_DEBUG` ? `DebugPage` : production error page);
`DebugToolbarMiddleware::inject()` appends the bar before `</body>`. We refactor
*what those produce*, not the request flow.

New/changed pieces:

- **`src/Http/Ui/` (new namespace)** — the shared UI layer:
  - `DesignSystem` — the single source of CSS custom properties (color tokens,
    spacing, typography, dark/light via `color-scheme`) plus shared base CSS.
    One `css(): string` returns the token block + primitives every surface
    inlines. This is the "one identity" foundation.
  - `SourceHighlighter` — `token_get_all()`-based PHP highlighter:
    `highlight(string $code): string` → HTML with `tok-*` spans; and an
    excerpt helper that highlights a window around a line and marks the error
    line. Pure, unit-testable, no I/O beyond the file read it's given.
  - `EditorLink` — builds an "open in editor" URL from `config('app.debug.editor')`
    (`vscode` → `vscode://file/{path}:{line}`, `phpstorm`, `sublime`,
    `textmate`, or a custom format string), null when unconfigured/disabled.
- **`src/Http/DebugPage.php`** — rewritten to compose `DesignSystem` +
  `SourceHighlighter` + `EditorLink` and emit the interactive layout
  (frames list ↔ source pane, tabs, copy, inlined JS). Keeps redaction + the
  fail-closed `section()` wrapper.
- **`src/Http/Middleware/DebugToolbarMiddleware.php`** — rewritten to emit an
  expandable bar (collapsed strip → click a segment → panel) using the shared
  design tokens, styles/JS inlined once. Same injection rules (HTML only,
  before `</body>`, strip stale Content-Length, never throw).
- **Production error pages** — a built-in branded HTML/CSS template set
  (400/401/403/404/405/419/429/500/503) drawn from `DesignSystem`, served by
  `ExceptionHandler::html()` when the host ships no `errors/{status}.twig`.
  Host `errors/*.twig` override still wins (BC). Skeleton + example
  `errors/*.twig` refreshed to match.
- **Start/welcome page** — skeleton `views/home/index.twig` (and the framework
  default landing if any) refreshed to the shared identity. The example's
  welcome already looks good; align its tokens to the shared palette.

### Interactive debug page — UX spec

- **Header:** status badge, exception class, message, location (with
  open-in-editor link when configured).
- **Two-pane body:** left = stack frame list (app frames highlighted, vendor
  frames de-emphasized, the throw frame selected by default); right = source
  pane showing the selected frame's file with `SourceHighlighter`, error line
  highlighted, ~12 lines of context, line numbers. Clicking a frame swaps the
  source pane (JS; no-JS fallback renders the throw frame's excerpt + the full
  trace table below, as today).
- **Tabs** (below or as a panel): Request (method/path/route/status/IP),
  Headers, Query/Body params, Cookies, Session — every value redacted. Plus a
  "Context" tab (PHP version, Ions version, memory, env name).
- **Actions:** "Copy as Markdown" (exception + trace to clipboard for issue
  reports) and per-frame open-in-editor links.
- **Previous-exception chain** preserved.

### Debug toolbar — UX spec

A fixed bottom strip (shared tokens) showing: wall time, route/target, query
count, peak memory, PHP+Ions version, and a close button. Each metric that has
detail is **clickable to expand a panel above the strip**: queries → the SQL
list with per-query timings; request → method/path/route/status; timing →
wall + memory. Collapsed by default; state is ephemeral (no cookies). Styles +
the tiny toggle JS inlined once per response. Unchanged injection safety rules.

## Testing strategy

- **`SourceHighlighter`** — unit tests: known PHP snippet → expected `tok-*`
  spans; the error line is wrapped; output is HTML-escaped (no XSS from source);
  malformed/partial PHP degrades without throwing.
- **`EditorLink`** — unit: each editor key → expected URI; unknown/disabled →
  null; path with spaces encoded.
- **`DesignSystem`** — smoke: `css()` returns the token block; non-empty;
  contains the documented custom properties.
- **`DebugPage`** — feature: a rendered page for a thrown exception contains the
  message, the highlighted source, the frames, the tabs; **redaction**: an
  `Authorization` header / `password` param / session secret is masked in the
  output (mutation-checked); the page is valid standalone HTML with inlined
  CSS/JS and **no external asset references**; rendering never throws even when
  the source file is unreadable.
- **`DebugToolbarMiddleware`** — feature (extend existing): the bar injects
  before `</body>`, only on HTML + `APP_DEBUG`, strips Content-Length; the
  expand panels are present; never injected on JSON; a render failure degrades
  to the un-injected response.
- **Production error pages** — feature: `APP_DEBUG=false`, an aborted request
  for each status renders the branded page (status + message), **no JS**, host
  `errors/{status}.twig` still overrides, the renderer never throws.
- **No-JS fallback** — assert the debug page still shows the throw excerpt +
  full trace table when JS is absent (the markup is present server-side).
- Gates: full pest zero-warnings, phpstan 5 + 8, php-cs-fixer, MySQL job.

## Sub-phases

- **14.1 Design system foundation** — `src/Http/Ui/DesignSystem` (+
  `SourceHighlighter`, `EditorLink` scaffolding with tests). The shared CSS
  tokens every later surface consumes. No behavior change yet.
- **14.2 Interactive debug page** — rewrite `DebugPage` onto the UI layer:
  clickable frames, syntax-highlighted source pane, tabs (request/headers/
  params/cookies/session/context), copy-as-markdown, open-in-editor, no-JS
  fallback. Redaction preserved + tested.
- **14.3 Debug toolbar upgrade** — expandable panels (queries SQL/timing/
  memory/request) on the shared tokens; safe injection unchanged.
- **14.4 Branded production error pages** — built-in 400/401/403/404/405/419/
  429/500/503 set from `DesignSystem`; host-template override preserved;
  skeleton + example `errors/*.twig` refreshed.
- **14.5 Start/welcome refresh** — skeleton (and framework default) landing
  aligned to the shared identity; example welcome palette aligned.
- **14.6 Wrap** — docs (`docs/errors.md`/`docs/debugging.md` as appropriate +
  the `app.debug.editor` / `app.debug_toolbar` config keys), CHANGELOG, final
  full-gate + browser smoke, review, merge. Version bump TBD at wrap (likely a
  minor — user-visible feature, no breaking change).

## Risks / decisions carried

- Inline JS/CSS bloats the debug HTML — acceptable, it's dev-only and the
  self-containment is a hard requirement. Keep the JS tiny (vanilla, no build).
- `token_get_all()` highlighting must be XSS-safe (escape token text before
  wrapping) — explicit test.
- Adding Session/Cookie tabs widens what the debug page displays — they MUST go
  through the same redaction; do not show raw session/cookie values.
- Production error pages must stay JS-free and tiny.
- Keep the host `errors/{status}.twig` override and the fail-closed behavior
  byte-for-byte in spirit (BC for hosts already shipping custom error pages).
