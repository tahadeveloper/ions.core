# Phase 7 — Illuminate 12, PHP 8.3 & Feature Expansion (Master Plan → 4.0.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. This is a MASTER plan: each sub-phase is specified at the task/file level and **MUST be expanded into its own detailed plan** (`docs/superpowers/plans/YYYY-MM-DD-phase7.N-<name>.md`) immediately before executing it — earlier sub-phases' design decisions (PHP 8.3 base, the container/provider conventions) shape later ones. Keep `composer qa` green throughout; new code at `strict_types` + PHPStan **level 8**; backward-compatible where cheap; every change TDD + reviewed; document breaks in `UPGRADE-4.0.md`.

**Goal:** Take the modernized framework (3.0.0, Illuminate 11 / PHP 8.2) onto **Illuminate 12 + PHP 8.3**, and expand it with first-class subsystems (filesystem drivers, session, console, cache/queue/events, auth completeness, API resources/OpenAPI) plus a class-hardening pass — shipping **4.0.0**.

**Architecture:** No architectural reset — every addition is a **service provider + container binding (+ middleware/contract where apt)** on the Phase 2–5 foundation. New subsystems follow the established pattern: a contract (interface), a manager/driver implementation, a `*Provider` that binds it, config under `config/<name>.php` or `app.*`, and a helper/facade for ergonomics. The `UserProvider` abstraction already decouples auth; the same driver-manager pattern generalizes to filesystem, cache, queue, and session.

**Tech Stack target (4.0.0):** PHP **8.3+**, **Illuminate 12.x**, Symfony 7.x, Monolog 3.x, **Cartalyst Sentinel v9/v10** (Illuminate 12, PHP 8.3), Carbon 3, Pest 3 / PHPUnit 11, `lcobucci/jwt` 5, `league/flysystem` 3 (+ adapters), `intervention/image` (new). PHPStan 2 (level-8 core, level-4+baseline legacy → burn down), PHP-CS-Fixer 3, Rector 2 (Laravel 12 set), GitHub Actions CI (PHP 8.3 [+ 8.4] + MySQL).

**Branch/release strategy:** All Phase 7 work on `main` via per-sub-phase feature branches (off `main`), merged `--no-ff` as each completes (the 3.0.0 cadence). Tag **`4.0.0`** when Phase 7 completes. The `1.x` and `3.x` lines remain for older PHP/Illuminate consumers; security fixes can be backported as before.

---

## ✅ Decisions captured (from scoping)
- **D-S(12) = Path A — bump minimum PHP to 8.3, keep Sentinel default.** Probe (2026-06-10): `cartalyst/sentinel` v9.0.0 / v10.0.0 support Illuminate 12 but **require PHP ^8.3**; v8 is Illuminate-11-only. So Illuminate 12 with Sentinel-as-default requires PHP 8.3. (The `EloquentUserProvider` remains the escape hatch for any future PHP-8.2 constraint, but Path A is chosen.) The PHP-floor bump makes this release a **major → 4.0.0**.
- **Scope (chosen):** Illuminate 12/PHP 8.3 base; multi-driver Filesystem; Session class; Console/Commands; Cache/Queue/Events; Auth completeness; API resources + OpenAPI; Class hardening + tracked items (`intervention/image`, baseline burn-down, static-Singleton removal).

## Sub-phase sequencing (rationale)
Foundational base first (everything sits on it), then the infrastructure subsystems the app layer needs (filesystem/session/console/cache), then the higher-level features (queue/events, auth, API resources), then the cross-cutting hardening, then release. Each sub-phase is independently shippable + reviewable.

1. **7.1 Illuminate 12 + PHP 8.3 base** (foundational; unblocks Sentinel v9/v10 + 8.3 language features)
2. **7.2 Filesystem — all drivers** (FilesystemManager over Flysystem; config-driven local/S3/ftp/etc.; Storage helper)
3. **7.3 Session** (session contract + driver-backed store; container binding + helper; CSRF/session integration)
4. **7.4 Console / Commands** (console Kernel, command discovery/registration, `make:*` modernized, `make:command`)
5. **7.5 Cache / Queue / Events** (formalize cache provider/facade; illuminate/queue jobs + provider; events/listeners dispatcher)
6. **7.6 Auth completeness** (per-user JWT binding through ApiController; login controller + rate-limit wiring; refresh/revoke endpoints; password reset)
7. **7.7 API resources + OpenAPI** (resource/transformer layer; form-request validation objects; OpenAPI/route export)
8. **7.8 Class hardening + tracked items** (strict_types/level-8 across more packages; baseline burn-down; static-Singleton removal; `intervention/image`)
9. **7.9 Release 4.0.0** (UPGRADE-4.0.md, CHANGELOG, docs, tag)

---

## Sub-phase 7.1 — Illuminate 12 + PHP 8.3 base
**Why first:** the PHP floor + Illuminate 12 underpin every later sub-phase (Sentinel v9/v10 need 8.3; 8.3 typed-class-constants/`#[\Override]` help the hardening pass).
**Spec / tasks (expand before executing):**
- Bump platform: `composer.json` `require.php` `^8.2` → `^8.3`; `config.platform.php` `8.2` → `8.3`; CI matrix → PHP 8.3 (+ optionally 8.4).
- Bump deps: all `illuminate/*` `^11` → `^12`; `cartalyst/sentinel` `^8.0` → `^9.0` (or `^10.0` — pick the one resolving with Illuminate 12 + the rest; v10 if it supports 12). Keep Symfony `^7`, Monolog `^3`, lcobucci `^5`, flysystem `^3`. `composer update --with-all-dependencies`; report resolved versions; confirm uniform Illuminate 12 + PHP 8.3.
- Rector: `LaravelSetList::LARAVEL_120`; dry-run → review → apply safe.
- Fix breakages until the **200-test** suite is green. Laravel 12 is a light major — expect few framework BC breaks; watch Sentinel v9/v10 API deltas (run SentinelUserProviderTest + GuardTest), Carbon, and any newly-deprecated Illuminate signatures. The level-8 core gate must stay 0.
- Baseline: regenerate only if third-party signatures shift; review for Ions-code defects.
- Docs: start `UPGRADE-4.0.md` (PHP 8.3 floor + Illuminate 12 + Sentinel v9/v10). `composer qa` green. Merge to `main`.
**Acceptance:** Illuminate 12, PHP 8.3 (uniform), Sentinel v9/v10, 200 tests green, level-8 core clean.

## Sub-phase 7.2 — Filesystem: easy support for all drivers
**Goal:** first-class, config-driven filesystem so apps swap local/S3/FTP/etc. trivially — generalize today's `IonDisk`/`FilesystemProvider` into a driver manager.
**Spec / tasks:**
- `Ions\Filesystem\FilesystemManager` — resolves named disks from `config('filesystem.disks')` (each `{driver: local|s3|ftp|sftp|memory, ...}`) into Flysystem `Filesystem` instances; `disk(?string $name)`; default disk from `config('filesystem.default')`. Lazy + cached per name.
- Driver factories for `local` (LocalFilesystemAdapter), `s3` (AwsS3V3Adapter — already a dep), `ftp`/`sftp` (add `league/flysystem-ftp` / `-sftp` as needed), `memory` (InMemory, for tests). Each gated by a small factory map; unknown driver → clear exception.
- `FilesystemProvider` binds `filesystem` (the manager) + `filesystem.disk` (default disk). A `Storage` helper/facade (`Storage::disk('s3')->put(...)`, `Storage::get(...)`) over the manager.
- Refactor `IonDisk` to delegate to the manager (BC: keep its static API as a thin shim over the default disk + the UploadValidator gate). Keep upload allow-listing.
- Tests: per-driver (local + memory for sure; s3 mocked/skipped without creds), the manager resolution, the Storage helper, IonDisk BC. Config reference in `docs/config.md`.
**Acceptance:** `Storage::disk('<name>')` works for the configured drivers; IonDisk stays BC; uploads still gated; tests green.

## Sub-phase 7.3 — Session class
**Goal:** a real session abstraction bound in the container (today CSRF uses `NativeSessionTokenStorage` directly).
**Spec / tasks:**
- `Ions\Session\SessionManager` / `Store` over Symfony HttpFoundation Session (or Illuminate session) with config-driven driver (`native`/`file`/`array`-for-tests) from `config('session.*')`. `session()` helper: `get/put/has/forget/flash/token/regenerate`.
- `SessionProvider` binds `session`; integrate with the request lifecycle (start on `handle()`, persist on response) via middleware (`StartSessionMiddleware`) in the web stack.
- Wire CSRF to use the bound session store (so `csrfToken()`/middleware share it cleanly — supersedes the direct NativeSessionTokenStorage).
- Tests: put/get/flash/regenerate over the array driver; CSRF still works end-to-end through the session-backed store; flash survives one request. Config docs.
**Acceptance:** `session()` works; CSRF uses the session store; web requests get a started/persisted session; tests green.

## Sub-phase 7.4 — Console / Commands
**Goal:** first-class console support (today `src/commands/*` are loose Illuminate Command classes via classmap).
**Spec / tasks:**
- `Ions\Console\Kernel` (or `ConsoleApplication`) wrapping `Illuminate\Console\Application` (Symfony Console under the hood) — boots the container, discovers + registers commands from `src/commands` (framework) and the host `app/Commands` (config `console.commands` + auto-discovery), provides `bin/ions` entrypoint contract.
- Modernize existing generators to the new base; add **`make:command`** generator. Ensure `make:middleware`/`make:service-provider` register through the kernel.
- A `schedule` hook (optional — wraps `peppeocchi/php-cron-scheduler` already in deps, or Illuminate scheduling) for registering scheduled commands.
- Tests: command discovery/registration; a sample command runs via `CommandTester`; `make:command` stub content. Docs `docs/console.md`.
**Acceptance:** `bin/ions list` shows framework + app commands; `make:command` works; generators run through the kernel; tests green.

## Sub-phase 7.5 — Cache / Queue / Events
**Goal:** formalize the three Illuminate subsystems behind providers.
**Spec / tasks:**
- **Cache:** `CacheProvider` binds `cache` = `Illuminate\Cache\CacheManager` driven by `config('cache.*')` (array/file/redis); `cache()` helper. Repoint `CacheRevocationStore`/rate-limiter to the shared `cache` manager (DRY).
- **Events:** `EventProvider` binds `events` = `Illuminate\Events\Dispatcher`; `event()`/`listen()` helpers; auto-register `config('events.listen')` map. Fire framework events (e.g. `RequestHandled`, `Authenticated`) at the right lifecycle points.
- **Queue:** `QueueProvider` binds `queue` = `Illuminate\Queue\QueueManager` (sync/database/redis); a `Job` base + `dispatch()` helper; a `queue:work` console command (depends on 7.4). DB driver migration/stub.
- Tests: cache put/get over array; event fire→listener; a job dispatched sync runs; queue:work processes one db job (sqlite). Config docs.
**Acceptance:** cache/events/queue resolve + work via helpers; revocation/rate-limit reuse the cache manager; tests green.

## Sub-phase 7.6 — Auth completeness
**Goal:** close the tracked per-user-JWT residual + ship the HTTP auth surface.
**Spec / tasks:**
- Per-user JWT binding: `ApiController` (and the AppKeys/JWT helpers) issue + verify tokens bound to the authenticated **user id** (not the app id); `AuthMiddleware` already resolves the user — align issuance.
- A `LoginController`/auth routes: `POST /auth/login` (validate credentials via `UserProvider`, rate-limited via `throttle`, issue access+refresh), `POST /auth/refresh` (rotate), `POST /auth/logout` (revoke), `POST /auth/password/forgot` + `/reset` (Sentinel reminders / Eloquent tokens). All return `Json`.
- Wire the `throttle` alias onto the login route by default in the framework's example api routes; document.
- Tests: login success/fail/rate-limited; refresh rotates + revokes; logout revokes (token then 401); password reset round-trip (Sentinel + Eloquent). Docs `docs/auth.md` updated.
**Acceptance:** full login/refresh/logout/reset over HTTP, per-user tokens, rate-limited; tests green.

## Sub-phase 7.7 — API resources + OpenAPI
**Goal:** typed response shaping + API docs.
**Spec / tasks:**
- `Ions\Http\Resource` (single) + `ResourceCollection` — transform models/arrays to response arrays (`toArray(Request)`), wrapping + pagination-aware; implement `Responsable` so controllers `return new UserResource($user)`.
- `Ions\Http\FormRequest` — a request-validation object (rules + authorize + validated()) integrating Illuminate Validation; controllers type-hint or resolve it; 422 on failure via `ExceptionHandler`.
- **OpenAPI/route export:** a `route:export`/`openapi:generate` console command that walks the RouteCollection (+ attribute routes) and emits an OpenAPI 3 JSON (paths/methods/middleware; resource/form-request schemas where derivable). Keep it pragmatic (route inventory + basic schemas), documented as best-effort.
- Tests: a resource shapes a model + a collection paginates; a form-request validates + 422s; the export command emits valid OpenAPI for the fixture routes. Docs `docs/resources.md`.
**Acceptance:** resources + form-requests work end-to-end; `openapi:generate` produces a valid spec for the routes; tests green.

## Sub-phase 7.8 — Class hardening + tracked items
**Goal:** raise the whole codebase's quality bar + clear tracked debt.
**Spec / tasks:**
- Extend `strict_types=1` + the level-8 gate to more packages (`Bundles`, `Support`, `Foundation`, `Filesystem`, `Session`, `Console`) incrementally — fix findings, don't baseline new ones. Burn down the ~74-entry legacy baseline (Sentinel facades stay as justified ignores; fix real ones).
- Replace remaining static `Singleton` access patterns with container resolution where it doesn't break BC; add `#[\Override]` (PHP 8.3) where applicable; tighten types/enums/readonly DTOs.
- **`intervention/image`:** add `Ions\Media\Image` (resize/crop/watermark/encode) over `intervention/image` ^3, restoring the image processing dropped with verot in 3.0.0; wire into IonUpload/Storage where apt. Tests for resize/encode.
- Tests stay green; raise main `phpstan.neon` level if the baseline absorbs it. Docs.
**Acceptance:** more packages at level-8/strict_types; baseline materially reduced; `Ions\Media\Image` ships with tests; suite green.

## Sub-phase 7.9 — Release 4.0.0
- `UPGRADE-4.0.md` (PHP 8.3 floor, Illuminate 12, Sentinel v9/v10, the new subsystems + any BC notes), `CHANGELOG.md` `## [4.0.0]`, README + `/docs` updates (filesystem/session/console/cache/queue/events/resources/auth), master-plan status.
- Final `composer qa` green; CI green on PHP 8.3(+8.4) + MySQL. Merge to `main`. Tag **`4.0.0`** (no `v` prefix) + push. Confirm it sits above the project's existing `3.0.0` / `2.1.0` history.

---

## Risks & mitigations
- **PHP 8.3 floor is a consumer break** → it's why this is 4.0.0; documented in UPGRADE-4.0; the 3.x/1.x lines remain for older runtimes.
- **Illuminate 12 is light but Sentinel v9/v10 API may shift** → the SentinelUserProvider/Guard tests + the UserProvider escape hatch; 7.1 is gated on the suite going green.
- **Scope is large (8 feature sub-phases)** → each is an independent provider/subsystem, expanded into its own plan + shipped + reviewed before the next; merge-as-you-go so value lands incrementally; can stop/re-prioritize between sub-phases.
- **New deps (ftp/sftp adapters, intervention/image, queue)** → add only when the sub-phase needs them; keep them in `require` only if core, else `suggest`.
- **Helpers/facades sprawl** → one helper per subsystem, thin, over the container binding; no logic in helpers.

## Self-review
- **Covers the chosen scope:** Illuminate 12/PHP 8.3 (7.1), Filesystem-all-drivers (7.2), Session (7.3), Console/Commands (7.4), Cache/Queue/Events (7.5), Auth completeness (7.6), API resources + OpenAPI (7.7), Class hardening + intervention/image + baseline (7.8), release (7.9). All requested areas mapped.
- **Decisions surfaced:** D-S(12) = Path A (PHP 8.3 + Sentinel v9/v10) recorded; release = 4.0.0 (PHP floor = major).
- **Honest about expansion:** this is a master plan; each sub-phase MUST be expanded into a detailed (bite-sized, TDD) plan before execution — the cascade principle (earlier decisions shape later code) makes pre-writing all micro-steps now guesswork.
- **Consistent conventions:** every subsystem = contract + manager/driver + Provider + config + thin helper, matching Phases 2–5.
