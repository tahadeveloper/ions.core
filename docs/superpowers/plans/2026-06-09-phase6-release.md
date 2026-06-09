# Phase 6 — DX, Docs & v2.0.0 Release (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development / executing-plans. Checkbox steps. `composer qa` green throughout (193 tests).

**Goal:** Final polish and ship **v2.0.0** — tighten static analysis on the new core packages, modernize the code generators, fix the last tracked bug, and write the docs (README + `/docs` + CHANGELOG) so the modernized framework is releasable. `UPGRADE-2.0.md` already exists and is maintained.

**Branch:** `phase6-release` off `main` (Phases 0–5 + Phase 4 complete; Illuminate 11; 193 tests).

**Current state:** README is 2 lines; no `CHANGELOG.md`; PHPStan baseline = 77 entries (legacy debt); generators in `src/commands/` (no `make:middleware`/`make:provider`); the tracked `IonDisk::download()` inverted-semantics bug is unfixed.

---

## Sub-phase 6.1 — Tighten static analysis on the NEW core packages
The whole codebase is at PHPStan level 4 + a 77-entry baseline. The Phase-1–5 code (`src/Security`, `src/Container`, `src/Http`, `src/Auth/Contracts`, `src/Providers`) is modern + typed — hold it to a higher bar without forcing the legacy code up.

- [ ] **Step 1:** Add a stricter PHPStan pass for the new packages. Simplest: a second config `phpstan-core.neon` (level 8, paths = `src/Security src/Container src/Http src/Auth/Contracts src/Providers src/View`, NO baseline) and a composer script `stan:core`. Run it; fix any level-8 findings in that code (they should be few — it was written clean). Add `@stan:core` to the `qa` script (or a `qa:strict`).
- [ ] **Step 2:** Add `declare(strict_types=1);` to the new core files (Security/Container/Http/Auth/Contracts/Providers/View) — they're new, low-risk. Run the suite (strict_types can surface loose-coercion bugs — fix any). Do NOT add strict_types to legacy files (out of scope).
- [ ] **Step 3:** Optionally bump the MAIN `phpstan.neon` to level 5 if the baseline absorbs it cleanly (regenerate baseline; removal/legacy only). Skip if it balloons. Commit `chore(qa): level-8 PHPStan gate on core packages; strict_types on new code`.

## Sub-phase 6.2 — Modernize generators
- [ ] **`make:middleware`** command: generates an `Ions\Http\Middleware\MiddlewareInterface` implementation stub (`handle(Request, callable $next): Response`) into the host `Http/Middleware`. Test: the generated stub references the interface + has a `handle` method.
- [ ] **`make:provider`** command: generates an `Ions\Container\ServiceProvider` stub (`register()`/`boot()`). Test similarly.
- [ ] **Modernize the controller/model/provider stubs** (`src/commands/stubs/*`, `controller/*`) to emit container-aware, return-`Response` controllers (e.g. `return Json::ok(...)` / `return $this->display(...)`), reflecting the Phase 2–3 architecture. Keep BC for existing generated code patterns where reasonable. Tests assert the stubs reference the modern APIs (no `echo`/`exit` in new controller stubs; use `Responsable`/`Json` where apt).
- [ ] Register the new commands wherever the host console app discovers `src/commands` (classmap). Commit `feat(generators): make:middleware + make:provider; modern return-Response controller stubs`.

## Sub-phase 6.3 — Fix the tracked `IonDisk::download()` inverted-semantics bug
- [ ] From the Phase 1 final review: `IonDisk::download()` has inverted read/write semantics (it uploads the local file to the cloud path instead of downloading). Read the method, write a failing test that asserts the CORRECT direction (download a stored file to a local destination), fix the implementation, confirm green. (Use the SQLite/local fixture + a temp file.) Commit `fix(storage): correct IonDisk::download() inverted read/write semantics (+ test)`.

## Sub-phase 6.4 — CHANGELOG.md
- [ ] Create `CHANGELOG.md` (Keep-a-Changelog format) with a `## [2.0.0]` entry summarizing the modernization by area: Security (JWT/upload/host/CSRF/query-injection), Architecture (container/providers/middleware/`Kernel::handle`), HTTP (return-Response controllers, single ExceptionHandler, routing/MRoute removal), Views (Twig-only), Auth (pluggable UserProvider, refresh/revocation, rate-limit), Dependencies (Illuminate 11, Symfony 7, Monolog 3, Pest 3, RedBean/Smarty/verot removed), DX (PHPStan/CS-Fixer/Rector/CI/MySQL, 193 tests). Link to `UPGRADE-2.0.md` for breaking changes. Commit `docs: add CHANGELOG.md (2.0.0)`.

## Sub-phase 6.5 — README + /docs
- [ ] Rewrite `README.md`: what Ions is, requirements (PHP 8.2+), install (composer), a quick-start (front controller `Kernel::boot(); Kernel::run();`, a route, a controller returning a Response), the host-app layout (config/routes/views/var/public; `src/` or `app/`), pointers to `/docs` + `UPGRADE-2.0.md`, badges (CI), license.
- [ ] Add a small `/docs` set (markdown): `docs/lifecycle.md` (boot → handle → pipeline → controller → response), `docs/routing.md` (Route fluent + attributes + `middleware()`), `docs/middleware.md` (interface + pipeline + the built-in stack + aliases), `docs/auth.md` (UserProvider, JWT issue/verify/refresh/revoke, AuthMiddleware, rate-limit, CSRF), `docs/config.md` (the `app.*`/`auth.*`/`database.*` keys — consolidate from `docs/phase2-config.md`). Keep concise + accurate to the code (cross-check key names/signatures). Commit `docs: real README + /docs (lifecycle, routing, middleware, auth, config)`.

## Sub-phase 6.6 — Release v2.0.0
- [ ] Final `composer qa` green; `composer validate` clean. Update the master plan: mark all phases complete.
- [ ] Merge `phase6-release` → `main`. Tag **`v2.0.0`** (annotated, message summarizing the release) and push the tag. Confirm CI is green on `main`.
- [ ] (Release housekeeping) Note the `1.x` security-backport line is still available off the pre-v2 history if production needs the Phase 1/4.1 fixes without v2's breaks — out of scope to create here unless requested.

---

## Deferred to a future minor (tracked, NOT v2.0.0-blocking)
- **`intervention/image`** replacement for the image resize/watermark dropped with verot (Task 1.2). Ship as v2.1 behind an `Ions\Media\Image` helper if demanded; document the removal in the meantime (already in UPGRADE-2.0.md).
- **Legacy baseline burn-down:** the 77-entry baseline is legacy-code debt (Sentinel facades, static Singleton access patterns). Burn down opportunistically; not release-blocking.

## Acceptance for Phase 6 / v2.0.0
- Core packages pass PHPStan level 8 (strict) + `strict_types`; generators emit modern code incl. `make:middleware`/`make:provider`; `IonDisk::download()` fixed; CHANGELOG + a real README + `/docs`; `composer qa` green; **`v2.0.0` tagged on `main`**. The modernization is shipped.

## Risks
- **strict_types / level-8 surfacing real bugs in core** — good (fix them); scoped to new code so volume is small.
- **Generator tests are awkward** (console I/O) — assert on emitted stub strings (as Phase 4.3 did) rather than running the full command.
- **Docs drift** — cross-check every documented signature/config key against the code; prefer fewer, accurate docs over comprehensive-but-wrong.
