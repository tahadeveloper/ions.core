# Phase 8 — Faster · More Secure · Richer · Easier · Smarter (Master Plan → 4.1.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing it. Gates throughout: `php83 vendor/bin/pest` green (292+), PHPStan level-5 main + level-8 core both 0, CS clean; new code `strict_types` + level-8; TDD + per-sub-phase review + merge-as-you-go (the Phase 7 cadence). Run everything on `php83` (default php is 8.2).

**Goal:** Take 4.0.0 from "feature-complete" to "production-grade": eliminate per-request rebuild costs (faster), close the second-tier audit gaps (secure), add the remaining app-framework facilities (features), ship a host-app skeleton + testing kit (easier), and make the framework convention-driven and self-diagnosing (smart).

**Grounded in two audits (2026-06-10; full reports in session):**
- **Perf:** route collection rebuilt EVERY request (`Kernel::handle()`→`captureRoute()`: file checks + parse + reflection attribute scan); Twig `Environment` re-created per render (`Traits/Twig::TwigInit`→`ViewFactory::make()`); middleware stacks re-instantiated per `handle()`; query log on under bare APP_DEBUG (unbounded); static Kernel state not worker-safe.
- **Security:** session cookies not secure-by-default; no `regenerate()` on login (fixation); no HSTS/Permissions-Policy; CORS default permissive (`*`); uploads checked by extension only (no magic bytes); no per-email throttle on password-forgot; no `composer audit` in CI; no SECURITY.md; no log redaction.

**Release framing:** **4.1.0** (additive) — except two security-DEFAULT flips (D8-1) shipped with loud UPGRADE-4.1 notes + opt-outs.

---

## Decisions to confirm (D8-x)
- **D8-1 Hardened defaults in a minor?** CORS → deny-by-default; session cookies → secure/httponly/samesite-lax by default. Recommended: ship in 4.1.0 (security-by-default) with UPGRADE notes + one-line opt-outs. Alternative: defer to 5.0.
- **D8-2 Worker mode:** state-reset + leak fixes ship normally; the FrankenPHP/RoadRunner runner ships EXPERIMENTAL.
- **D8-3 "Smart":** auto-discovery + `ions doctor` + N+1 detector ship; an optional `Ions\AI` LLM module (scaffolding assist / log summarization) only if the user confirms — suggest-only dependency.

## Sequencing
1. **8.1 Performance** (biggest measurable win) → 2. **8.2 Worker-mode safety** → 3. **8.3 Security hardening II** → 4. **8.4 DX: skeleton + testing kit** → 5. **8.5 Features** → 6. **8.6 Smart** → 7. **8.7 Release 4.1.0**

---

## 8.1 Performance — compile + cache the hot path
- **Route cache:** `route:cache` compiles the full RouteCollection (php/yaml + attribute routes, web+api) to `var/cache/routes.php` (CompiledUrlMatcher-style dump); `captureRoute()` loads it when present; `route:clear`; bypass when APP_DEBUG. Kills the per-request file/parse/reflection cost.
- **Capture once per process even uncached:** guard `static::$collection` per route-group so `handle()` never rebuilds within a process.
- **Config cache:** `config:cache` merges `config/*.php` → `var/cache/config.php`; `captureConfig()` prefers it; `config:clear`.
- **Twig singleton:** bind the built `Environment` once (`view.env` singleton); `ViewFactory::make()`/`Traits/Twig` reuse it (Twig file cache stays on).
- **Middleware prebuilt:** web/api stacks computed once (container singletons), per-route middleware instances cached.
- **Query log gating:** only on explicit `config('database.query_log')`; bounded buffer.
- **`optimize` / `optimize:clear`** umbrella commands + `preload:generate` (opcache preload file, optional).
- **Benchmark harness:** measure `handle()` wall-time before/after on the fixture (N iterations) — report real numbers, not claims.
**Acceptance:** measured speedup; caches off in debug; commands shipped; 292+ green.

## 8.2 Worker-mode safety (experimental runner)
- `Kernel::resetForRequest()` clears per-request statics (`$request`/`$response`/session state) keeping boot state; audit `Singleton::$instances`, RequestStack, query log for cross-request leaks; make `StartSessionMiddleware` reset-safe.
- `Ions\Runtime\WorkerRunner` (EXPERIMENTAL): boot once → per request: reset → handle → emit; `docs/worker-mode.md` with a FrankenPHP example.
- Tests: two sequential `handle()` calls after reset — different sessions, fresh response, no accumulated state.
**Acceptance:** proven request isolation; runner shipped experimental.

## 8.3 Security hardening II (audit closures)
- **Session cookies secure-by-default:** `cookie_httponly=true`, `samesite=lax`, `secure=true` (HTTPS autodetect; explicit config to relax). Omission = secure. **(D8-1)**
- **Fixation:** `session()->regenerate()` on successful login (web session present; stateless API unaffected).
- **Headers:** HSTS (config `app.security.hsts`, HTTPS-only) + `Permissions-Policy` (restrictive default) in `SecurityHeaders`; CORS default `origins: []` (deny) + `Access-Control-Allow-Credentials` only when explicit and origins !== `*`. **(D8-1)**
- **Upload magic-bytes:** `UploadValidator` verifies content (finfo) agrees with the extension (ext→mime allow-map); rejects polyglots (.jpg containing PHP). First-bytes only for perf.
- **Password-forgot throttle:** per-email+IP limiter (tighter than route throttle).
- **CI:** `composer audit` (fail high/critical) + dependabot config. **SECURITY.md.** **Log redaction** Monolog processor (password/token/authorization keys).
- Backlog (4.2 candidates, not blocking): 2FA/TOTP module, email-verification flow, refresh-token family tracking, CSP report-uri.
**Acceptance:** every HIGH audit item closed with a test (cookie flags, regenerate-on-login, HSTS/PP present, polyglot rejected, forgot-throttle 429, audit job green); UPGRADE-4.1 notes for the default flips.

## 8.4 DX — skeleton, testing kit, generators
- **Host-app skeleton** (`skeleton/` in-repo, create-project documented; or `ionzile/app` repo — decide at execution): front controller, `bin/ions`, secure-default config set, routes/views/var layout, README quick-start. The single biggest "easy to work with" win.
- **`Ions\Testing\TestCase`** for host apps: boots against a base path; `$this->get('/x')`/`post()` → response wrapper (`assertStatus/assertJson/assertSee`); `actingAs($user)` (issues a real JWT); **fakes**: `Queue::fake()`, `Event::fake()`, `Storage::fake()` (memory disk), `Mail::fake()` with assertion helpers (`assertDispatched/assertFired/assertStored/assertSent`).
- **Generators:** `make:resource`, `make:request`, `make:job`, `make:event`, `make:listener`, `make:test`; align all stubs with the skeleton conventions.
- **Better debug page:** improve the debug HTML error rendering in `ExceptionHandler` (trace + request summary + syntax-highlighted frame) — still zero info-leak in production.
- **IDE meta / helper stubs** for container ids + helpers (PhpStorm meta file shipped).
**Acceptance:** create-project → working app in <5 min; host tests writable with the kit + fakes; generators cover the common artifacts.

## 8.5 Features — remaining app-framework facilities
- **HTTP client:** `Ions\Http\Client` thin wrapper over Symfony HttpClient or Guzzle (pick at execution; prefer symfony/http-client — already in the Symfony family): `get/post/json/withToken/timeout/retry`, PSR-friendly, fakeable for tests.
- **Mail upgrade:** `Ions\Mail\Mailable` (build emails as classes), `queue()`able via the queue subsystem; keep `newMailerDsn()` BC.
- **Notifications:** `Ions\Notifications\Notification` with `mail` + `database` channels (notifications table stub), `notify($user, $notification)`.
- **Signed URLs + Encrypter:** `Ions\Security\Encrypter` (sodium, APP_KEY-derived) + `Ions\Security\UrlSigner` (`signedRoute()`/`hasValidSignature()` middleware) — enables verify-email/unsubscribe links.
- **Model factories:** `Ions\Database\Factory` minimal model-factory support for tests/seeding (works with the fixture + host tests).
- (Each = contract + provider/binding + helper + tests + docs; all OPTIONAL features must not slow the hot path when unused.)
**Acceptance:** each facility usable in 3 lines from a host app, tested, documented.

## 8.6 Smart — conventions, diagnostics (+ optional AI per D8-3)
- **Auto-discovery:** host providers/commands/listeners auto-registered by convention (`app/Providers`, `app/Commands`, `config('events.listen')`) + composer-extra discovery for third-party Ions packages (`extra.ions.providers`) — zero-config package installs.
- **`ions doctor`:** diagnoses the host app — env keys present/typed (APP_KEY length!), writable var/, cache states (route/config cached?), DB connectivity, PHP extensions, security posture summary (CSRF on? trusted hosts set? cookies secure?). Exit non-zero on critical misconfig.
- **N+1 query detector** (debug-only): flags repeated single-row queries within a request via the bounded query log; logs a warning with the offending pattern.
- **Typed config accessor:** `Config::string('app.name')` / `int()/bool()/array()` (throws on type mismatch) — kills a whole class of config bugs.
- **Optional `Ions\AI` (only if D8-3 confirmed):** thin client (Anthropic-first) behind `suggest`; used by `make:* --ai` scaffolding assist and `log:summarize`. Never in the request hot path.
**Acceptance:** zero-config discovery works (tested with a fake package fixture); `doctor` catches seeded misconfigs; N+1 warning fires on a crafted fixture; typed config throws on mismatch.

## 8.7 Release 4.1.0
- UPGRADE-4.1.md (the D8-1 default flips + opt-outs lead), CHANGELOG `[4.1.0]`, README/docs updates (perf commands, testing kit, skeleton quick-start, doctor), benchmark numbers in the release notes. Tag `4.1.0` (no `v`).

---

## Risks & mitigations
- **Default flips (D8-1) break permissive hosts** → loud UPGRADE notes + single-config opt-outs; decision surfaced before 8.3 executes.
- **Route/config caches serving stale data** → debug bypass + `*:clear` + `optimize:clear`; cache invalidation documented; tests compare cached vs live matches.
- **Worker mode is genuinely hard** → scoped: reset + isolation tests ship; the runner is explicitly experimental; no default behavior change.
- **Scope breadth** → same Phase-7 discipline: one sub-phase at a time, shippable + reviewed + merged; re-prioritize between sub-phases at will.
- **Magic-bytes validation false-positives** (legit files with odd MIME) → allow-map config + clear error; extension gate remains the primary control.

## Self-review
- Faster → 8.1/8.2 (route+config+Twig+middleware caching, measured; worker mode). Secure → 8.3 (every audit HIGH/MED closed or backlogged explicitly). Features → 8.5. Easy → 8.4 (skeleton, testing kit, generators, debug page). All-new-system-options → 8.5+8.1 commands (+ skeleton). Smart → 8.6 (discovery, doctor, N+1, typed config, optional AI). All five asks mapped.
- Decisions surfaced (D8-1/2/3) rather than assumed; audits cited with file-level findings; benchmarks required so "faster" is proven, not claimed.
