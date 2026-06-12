# Phase 14 — Framework UI & Developer Experience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: subagent-driven-development —
> one fresh subagent per sub-phase, two-stage review (spec then quality),
> merge-as-you-go. Steps use checkbox syntax for tracking.

**Goal:** A cohesive first-party UI across the debug page, debug toolbar,
production error pages, and start page — with a full interactive (Whoops-class)
debug experience — dependency-free, redaction + dev-only gating preserved.

**Architecture:** A new `src/Http/Ui/` layer (`DesignSystem`,
`SourceHighlighter`, `EditorLink`) that all four surfaces consume; `DebugPage`
and `DebugToolbarMiddleware` rewritten onto it; built-in branded production
error pages; refreshed start pages. Self-contained inline CSS/JS; server-side
`token_get_all()` highlighting; no new Composer deps.

**Tech Stack:** PHP 8.3, Twig (host error templates), vanilla inline JS, Pest.

See the design doc: `docs/superpowers/specs/2026-06-12-framework-ui-debug-experience-design.md`.

---

## Conventions for every sub-phase

- `php83` for ALL tooling. TDD: failing test first.
- Gates before "done": `php83 vendor/bin/pest` (zero warnings) · `php83 vendor/bin/phpstan analyse` · `php83 vendor/bin/phpstan analyse -c phpstan-core.neon` · `php83 vendor/bin/php-cs-fixer fix --dry-run` (0 files). New PSR-4 code: `declare(strict_types=1)`, level-8 clean.
- Self-contained: no `<link>`/`<script src>` in error/debug HTML. Inline only.
- Fail-closed: never let the error/debug/toolbar path throw.
- One branch + one commit per sub-phase; merge to main after two-stage review.

---

## Task 14.1 — Design system foundation

**Files:** Create `src/Http/Ui/DesignSystem.php`, `src/Http/Ui/SourceHighlighter.php`, `src/Http/Ui/EditorLink.php`; tests `tests/Feature/Http/Ui/{DesignSystemTest,SourceHighlighterTest,EditorLinkTest}.php`.

- [ ] `DesignSystem::css(): string` returns a `:root{--…}` token block (color palette: bg/surface/text/muted/accent/danger/vendor; spacing; mono+sans font stacks; `color-scheme: dark light`) plus shared primitives (badge, code/pre, table, link). Tested: non-empty, contains the documented custom-property names, no `<` injection.
- [ ] `SourceHighlighter::highlight(string $php): string` tokenizes via `token_get_all()` and wraps each token in `<span class="tok-{kind}">` with the **text HTML-escaped**; unknown/partial input degrades to an escaped `<pre>` without throwing. `excerpt(string $file, int $errorLine, int $pad = 6): string` returns the highlighted window with line numbers and the error line marked `.line-err`. Tests: known snippet → expected token classes; XSS payload in source is escaped; missing file → '' (no throw).
- [ ] `EditorLink::for(string $file, int $line): ?string` reads `config('app.debug.editor')` and maps `vscode`/`phpstorm`/`sublime`/`textmate`/custom-format → URI; `null` when unset/`false`. Tests: each key → expected URI; unknown → null; spaces encoded.
- [ ] Commit: `feat(ui): shared DesignSystem + SourceHighlighter + EditorLink (Phase 14.1)`.

## Task 14.2 — Interactive debug page

**Files:** Rewrite `src/Http/DebugPage.php`; tests `tests/Feature/DebugPageTest.php` (extend/replace).

- [ ] Compose `DesignSystem`+`SourceHighlighter`+`EditorLink`. Layout: header (status/class/message/location+editor link); two-pane (frame list ↔ highlighted source pane, throw frame selected, app vs vendor styling); tabs Request/Headers/Params/Cookies/Session/Context — ALL redacted via the existing redaction; actions Copy-as-Markdown + open-in-editor. Inline the tiny vanilla JS (frame switch, tab switch, copy) and all CSS. No external asset refs.
- [ ] No-JS fallback: the throw-frame excerpt + the full trace table + all tab tables are present in server-rendered markup (JS only toggles visibility).
- [ ] Keep the fail-closed `section()` wrapper; rendering never throws on unreadable source/edge data.
- [ ] Tests: rendered page contains message + highlighted source + frames + tabs; **redaction** mutation-checked (Authorization header / password param / session value masked); valid standalone HTML, zero external asset references; unreadable source still renders.
- [ ] Commit: `feat(http): interactive debug page — clickable frames, syntax highlight, tabs, copy/editor (Phase 14.2)`.

## Task 14.3 — Debug toolbar upgrade

**Files:** Rewrite `src/Http/Middleware/DebugToolbarMiddleware.php`; tests `tests/Feature/.../DebugToolbarMiddlewareTest.php` (extend).

- [ ] Expandable bar on shared tokens: collapsed strip (time/target/queries/memory/PHP+Ions) → clicking a segment expands a panel above the strip (queries → SQL list + per-query timing; request → method/path/route/status; timing → wall+memory). Inline styles + tiny toggle JS once. Ephemeral (no cookies).
- [ ] Unchanged injection safety: HTML + `APP_DEBUG` only, inject before `</body>`, strip stale Content-Length, never throw, never on JSON.
- [ ] Tests: panels present; injection rules intact; degrades on failure; not injected on JSON.
- [ ] Commit: `feat(http): expandable debug toolbar with query/timing panels (Phase 14.3)`.

## Task 14.4 — Branded production error pages

**Files:** Create `src/Http/Ui/ErrorPage.php` (built-in branded page from `DesignSystem`); wire in `src/Http/ExceptionHandler.php::html()` as the built-in fallback (host `errors/{status}.twig` still wins); refresh `skeleton/views/errors/*.twig` + `examples/taskflow/views/errors/*.twig`. Tests `tests/Feature/.../ErrorPage*Test.php`.

- [ ] `ErrorPage::render(int $status, string $message): string` → branded, JS-free, inline-CSS page (big status, message, home link) for 400/401/403/404/405/419/429/500/503. `ExceptionHandler::html()` (production branch) uses it instead of the bare `<h1>` when no host template exists.
- [ ] Host `errors/{status}.twig` / `{4xx|5xx}.twig` override preserved (BC); renderer never throws.
- [ ] Tests: `APP_DEBUG=false` abort per status → branded page (status+message), no `<script>`; host template overrides; fail-closed.
- [ ] Commit: `feat(http): branded built-in production error pages (Phase 14.4)`.

## Task 14.5 — Start / welcome refresh

**Files:** `skeleton/views/home/index.twig` (and any framework default landing); align `examples/taskflow/views/home/index.twig` + error pages to shared palette. Tests: skeleton/example smoke still green.

- [ ] Refresh the skeleton welcome to the shared identity (tokens/palette consistent with DesignSystem); keep it a self-contained Twig page. Align the example welcome palette.
- [ ] Commit: `feat(skeleton): start page aligned to the framework design system (Phase 14.5)`.

## Task 14.6 — Wrap

- [ ] Docs: a `docs/errors-and-debugging.md` (or extend existing) covering the debug page, toolbar, production error pages, `app.debug.editor`, `app.debug_toolbar`; CHANGELOG `[Unreleased]` entries; mention in main README features.
- [ ] Final: full pest + phpstan 5/8 + cs + example suite green; browser smoke (`ions serve`) of a thrown exception (debug page), the toolbar, and a 404 (prod page). Decide version bump (likely minor). Review, merge.
- [ ] Commit: `docs: errors & debugging guide; CHANGELOG (Phase 14.6)`.

## Self-review

- Spec coverage: design system (14.1) → debug page (14.2) → toolbar (14.3) →
  prod error pages (14.4) → start page (14.5) → wrap (14.6). All four surfaces +
  the interactive debug requirement covered.
- Security: redaction carried into 14.2's new tabs; production pages JS-free;
  dev-only gating unchanged.
- No external asset refs; no new deps; fail-closed everywhere.
