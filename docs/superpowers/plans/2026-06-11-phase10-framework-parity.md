# Phase 10 — Framework Parity Implementation Plan (→ 4.3.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing it (the dispatch prompt carries full grounded detail). Gates throughout: `php83 vendor/bin/pest` green zero warnings (934+ baseline), PHPStan level-5 main + level-8 core both 0, new PSR-4 code `strict_types`, TDD + two-stage review + merge-as-you-go. Run everything via `php83`.

**Goal:** Close the highest-value gaps vs Symfony/Laravel/Slim: trusted proxies, route model binding, pagination + the web form flow, Gate/policies, queue resilience, the smart trio (/up, toolbar, ORM strict), and the cheap-wins batch — released as 4.3.0.

**Architecture:** 10.1/10.2 extend existing seams (boot + ActionArgumentResolver). 10.3–10.7 are new lazy-bound facilities following the 8.x/9.x provider/facade/command conventions. Riskiest integration: 10.3's FormRequest content negotiation (API contract must stay byte-identical).

**Tech Stack:** PHP 8.3, Illuminate (paginator, Eloquent strict, queue worker), Symfony HttpFoundation (trusted proxies), nyholm/psr7 + symfony/psr-http-message-bridge (10.7 only).

**Spec:** `docs/superpowers/specs/2026-06-11-phase10-framework-parity-design.md`.

---

## 10.1 Trusted proxies
Files: `src/Foundation/Kernel.php` (boot: apply `app.trusted_proxies` + `app.trusted_proxy_headers`; `resetForTesting()` clears), `src/Foundation/Doctor.php` (+check), config/docs sweep (UPGRADE-4.1 caveats softened, config.md, deploy.md, skeleton config comment). Tests: simulated proxy (trusted REMOTE_ADDR + XFF/XFP honored → isSecure/clientIp; untrusted ignored), HSTS now emitted behind proxy, `'auto'` cookie secure, reset hygiene, doctor row.
Commit: `feat(http): trusted proxies — app.trusted_proxies config, doctor check, proxy-aware secure detection`.

## 10.2 Route model binding
Files: `src/Http/ActionArgumentResolver.php` (Model-subclass + placeholder-name rule before the generic object rule; routeKeyName; nullable→null; miss→404 via abort; no-db→clear error), fixture model + routes, `docs/controllers.md` (+binding section). Tests: bind/miss/nullable/routeKeyName/regression pins for 9.3 rules.
Commit: `feat(http): implicit route model binding in the action resolver`.

## 10.3 Pagination + web form flow + Redirect modernization
Scope add (user request 2026-06-11): fluent Redirect — `redirect()` helper returning a builder: `->route($name,$params)`, `->back()`, `->with()`, `->withErrors()`, `->withInput()`, `->away()`, status/headers; existing static Redirect BC. Implement alongside the flash machinery (same session plumbing).
Files: paginator wiring (lazy resolvers → Ions request) in DatabaseProvider or ViewProvider (ground it), `src/View/PaginationExtension.php` + default template, helpers `back()/old()/flash()`, redirect-with-errors/input via session flash, Twig `errors`/`old()`, `src/Http/FormRequest.php` content-negotiated failure (web → 302 back + flash; JSON/api → 422 unchanged — pin existing tests), docs (views.md/controllers.md/best-practices.md + new docs/forms.md if cleaner). Tests per spec acceptance.
Commit: `feat(http+view): pagination + flash/old/back web form flow; FormRequest web redirects`.

## 10.4 Gate & policies
Files: `src/Auth/Gate.php` + lazy 'gate' binding (AuthProvider or new), `can()` helper + Twig `can()`, `authorize()` on Base/Api controllers, docs/auth.md section + best-practices. Tests per spec.
Commit: `feat(auth): Gate + policies — define/allows/authorize, can() helper and Twig function`.

## 10.5 Queue resilience
Ground what Illuminate's worker already gives (tries/backoff/failed-job provider exists in the queue manager wiring?). Files: failed_jobs stub, failer binding, `queue:failed/retry/forget/flush` commands, Job docblock + docs. Tests per spec (frozen/spy timing for backoff).
Commit: `feat(queue): failed-jobs table + retries/backoff + queue:failed/retry commands`.

## 10.6 Smart trio + error pages + welcome page
Scope add (user request 2026-06-11): custom HTML error pages — ExceptionHandler html() path tries views/errors/{status}.twig then {4xx|5xx}.twig then built-in (never-throw: template failure -> built-in + view.log warning; debug mode keeps DebugPage); skeleton example errors/404.twig; polished skeleton welcome page (views/home/index.twig rewrite, inline CSS, no deps).
Files: /up route in captureRoute (+ `app.health.*` config + token gate to doctor JSON), `src/Http/Middleware/DebugToolbarMiddleware.php` (debug-only attach in defaultMiddleware; HTML-only injection), ORM strict toggles in DatabaseProvider::boot (debug && database.strict, default true; escape hatch), docs (console.md/performance.md/config.md/deploy.md health probe). Tests per spec.
Commit: `feat(smart): /up health endpoint, debug toolbar lite, ORM strict mode in debug`.

## 10.7 Core services modernization — Route, Logs, Filesystem/IonDisk
Scope add (user request 2026-06-11) — see spec §10.7. Files: `src/Bundles/Route.php` (fluent name()/where(), Route::redirect/view/fallback, group name+middleware prefixes), `route()` helper in helpers.php, `config/logging.php` channel system + `Ions\Support\Log` facade (single/daily/stderr/stack drivers, per-channel level; Logs::create BC shim), IonDisk/IonUpload rerouted through FilesystemManager (Storage::fake gap closed — flip the 8.4 caveat test) + richer Storage API (download()/url()/temporaryUrl()/files()/directories()/copy/move/putFile). Each surface its own commit; two-stage review per the cadence. Acceptance per spec §10.7.

## 10.8 Cheap wins
Files: `src/Http/Middleware/Psr15Adapter.php` (+ nyholm/psr7 + bridge deps), `ions down/up` commands + early 503 check in handle + var/ flag file + doctor row, `ions serve` command, docs. Tests per spec (real PSR-15 middleware fixture; maintenance bypass cookie; serve command arg building — no real server spawn).
Commit: one per item or one batch — implementer's call, keep reviewable.

## 10.9 Release 4.3.0
CHANGELOG assembly, UPGRADE-4.3 (ORM strict + FormRequest web behavior + model-binding empty-model note from 10.2 + IonDisk-via-manager note), best-practices/README updates, fact-check review (8.7 bar), merge, push, tag 4.3.0 locally — user confirms push.

## Self-review
Spec coverage 10.1–10.8 ✓ task-per-section with files/tests/acceptance; decisions deferred-to-execution are explicit (paginator wiring location, strict default, PSR-15 alias ergonomics, 10.7 commit granularity); no placeholders; types referenced exist as of 4.2.0 (ActionArgumentResolver, Doctor, FormRequest, defaultMiddleware, captureRoute).
