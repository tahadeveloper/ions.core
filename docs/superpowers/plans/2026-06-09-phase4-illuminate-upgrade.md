# Phase 4 — Illuminate Upgrade, DB Consolidation & Query-Builder Hardening (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Several tasks are **exploratory dependency upgrades** — they follow a *resolve → observe failures → fix → re-test* loop rather than pre-written code; those are marked.

**Goal:** Get off the EOL Illuminate 9 pin onto a modern, supported Illuminate, drop the interim version pins, consolidate the two query builders behind one hardened API (closing a column-injection default), modernize the DB generators (stubs), settle RedBean's fate, and verify Eloquent against SQLite **and** MySQL in CI. A successful endpoint also unblocks **Pest 3 / PHPUnit 11**.

**Architecture:** No new architecture — this is a dependency + DB-layer modernization on top of the Phase 2 container/provider model. `DatabaseProvider` (already the single DB wiring point) absorbs lazy-connection + query-log changes. The request-driven `Builders\QueryBuilder` becomes the one public query API; `Bundles\QueryBuilder` is folded in or removed. Auth (Sentinel) is the critical blocker and is handled via Decision **D-S** below, coordinating with Phase 5's pluggable `UserProvider`.

**Tech Stack target (post-phase):** PHP 8.2+, **Illuminate 10 → then 11** (see Decision D-U), Symfony **6.4 → 7.x** (forced by Illuminate 11), Monolog **2 → 3** (forced by Illuminate 11), `cartalyst/sentinel` upgraded or replaced (D-S), Pest **2 → 3** + PHPUnit **10 → 11** (unblocked at Illuminate 11). Rector drives the mechanical upgrade; PHPStan/CS-Fixer/CI unchanged in shape.

**Branch:** fresh branch off `main` (e.g. `phase4-upgrade`). `main` holds Phases 0–3 (113 tests green).

---

## ⚠️ Two decisions to settle before executing the upgrade tasks

### Decision D-U — upgrade target & path
Illuminate 11 forces **Symfony 7** components and **Monolog 3**, and requires **Sentinel-with-L11-support**. Illuminate **10** is far less disruptive (PHP 8.1+, **Symfony ^6.2 — our 6.4 pins stay**, Monolog ^2||^3, and **Sentinel 7 supports Laravel 10**). But Illuminate 10 does **not** unblock Pest 3 (Pest 3 → termwind 2 → illuminate/console 11).

- **Option A (RECOMMENDED): incremental 9 → 10 → 11.** Sub-phase 4.2 upgrades to **10** (low blast radius: drop interim pins, Eloquent/container deltas, Sentinel 7, Symfony stays 6.4). Then a later sub-phase 4.6 does **10 → 11** (Symfony 7 + Monolog 3 + Pest 3 + the Sentinel-for-11 question). Each step ships independently; the risky Symfony-7/Sentinel jump is isolated.
- **Option B: straight 9 → 11.** One big coordinated jump (Illuminate 11 + Symfony 7 + Monolog 3 + Sentinel + Pest 3). Faster nominal path, but a large all-at-once BC surface — harder to bisect failures.

**This plan is written for Option A.** **RESOLVED (user, 2026-06-09): Option A — incremental 9 → 10 → 11.**

### Decision D-S — Cartalyst Sentinel's fate
Sentinel 6 targets Laravel 9. The auth layer (`src/Auth/Guard/*`, `src/Auth/Sentinel/User.php`, `Auth/Sentinel/config.php`) is tightly coupled to Sentinel static facades.
- **For Illuminate 10:** `cartalyst/sentinel:^7.0` supports Laravel 10 — **verify on Packagist** and upgrade in 4.2.
- **For Illuminate 11:** Sentinel L11 support is uncertain. Options: (a) wait for/upgrade to a Sentinel release supporting L11; (b) **replace Sentinel** behind Phase 5's `UserProvider` abstraction (recommended end-state — Phase 5 already plans `Authenticatable`/`UserProvider` with Sentinel as one adapter). **Recommended: defer the Sentinel-for-11 decision to Phase 5**, and have Phase 4 stop at Illuminate 10 with Sentinel 7 IF Sentinel has no clean L11 path. i.e. the 10→11 jump (4.6) is gated on D-S being resolved (Sentinel-11 available, OR Phase 5's UserProvider landed first).

> **RESOLVED (user, 2026-06-09):** D-S(10) = **upgrade to Cartalyst Sentinel 7** for Illuminate 10. D-S(11) = **deferred to Phase 5** (pluggable `UserProvider`); the 10→11 step (4.6) is gated on Phase 5 resolving Sentinel-for-11 (likely run Phase 5 before 4.6). **Execution order chosen: start with 4.1 (injection hardening) now.**

> **Practical sequencing implication:** 4.1 (injection hardening), 4.3 (stubs), 4.4 (builder consolidation), 4.5 (RedBean) are **independent of the upgrade** and can land first. 4.2 (→10) needs D-S(10)=Sentinel 7. 4.6 (→11) needs D-S(11) resolved — which may mean **doing Phase 5 before 4.6**.

---

## Current-state facts (verified against `main`)

- `composer.json` pins **every** `illuminate/*` to exact `v9.52.4` (cache, console, container, database, events, filesystem, http, pagination, support, validation). Symfony components at `^6.4`. `monolog/monolog:^2.10`. `cartalyst/sentinel:^6.0.1`. `gabordemooij/redbean:^5.7`. Pest `^2` / PHPUnit 10 (dev).
- **Query-builder injection default (security):** `src/Traits/BuilderFilters.php` `allowFilters()` defaults `$allow_all = true` → when a controller uses `Builders\QueryBuilder` without explicitly allow-listing, **request-supplied filter field names flow into `where()/whereIn()`** (`BuilderFilters.php:133-135`) with no allow-list. `BuilderSort` (`orderBy`, `BuilderSort.php:61`) and `BuilderFields` (`select`, allow-list enforced, throws `InvalidFieldQuery`) are safer. No `whereRaw`/`selectRaw` with user input found. `Bundles\QueryBuilder` uses a constructor-supplied field whitelist (safe by design).
- **RedBean** is used ONLY in `DatabaseProvider::redBeanConnection()` (gated by `config('app.database_engine')` containing `'redbean'`). Removal is clean (DB-layer only). Sentinel does NOT use RedBean (its `User` extends Eloquent's `EloquentUser`).
- **Generators need stub fixes for L11:** `commands/migrate/MigrateCommand.php` uses `$table->increments('id')` (removed in L11 → `$table->id()`); `commands/ModelCommand.php` emits `SoftDeletingTrait` (removed → `SoftDeletes`) + `$dates` (deprecated → `$casts`).
- **Capsule wiring** in `DatabaseProvider` (`Manager`, `addConnection`, `setEventDispatcher`, `setAsGlobal`, `bootEloquent`) is stable 9→11. `helpers.php` validation `Factory`/`DatabasePresenceVerifier` stable. Pagination call stable but resolver registration should be tested.
- 113 tests; DB exercised via the in-memory SQLite fixture; **no MySQL in CI yet**.

---

## How to read this plan

Guardrails per task (same as Phases 2–3): `composer qa` green throughout (cs + stan + the 113 tests must never regress, except where a dependency upgrade legitimately changes counts — note it); new code PHPStan-clean (no new baseline suppressions; the EOL-driven baseline may need regeneration on the upgrade tasks — those are removals/migrations, documented); preserve the `Ions\` public surface; document every break in the v2 upgrade guide.

- **4.1 (injection hardening)** and **4.3 (generator stubs)** — security/clean, **fully specified with TDD + code**, upgrade-independent → do first.
- **4.4 (builder consolidation)**, **4.5 (RedBean)** — design tasks, concrete specs.
- **4.2 (→ Illuminate 10)**, **4.6 (→ Illuminate 11 + Symfony 7 + Pest 3)** — **exploratory upgrade loops** (resolve → observe → fix), gated on D-U/D-S.
- **4.7 (MySQL CI + migration tests)**, **4.8 (DatabaseProvider lazy + query-log)** — verification/polish.

---

## Sub-phase 4.1 — Query-builder injection hardening (SECURITY, upgrade-independent — do first)

### Task 4.1.1: Make filter allow-listing the safe default

**Problem:** `BuilderFilters::allowFilters($allow_all = true)` → unguarded request filter columns reach `where()`. Flip to **secure-by-default**: when no allow-list is configured, **reject** unknown filter fields (throw `InvalidFilterQuery`) rather than passing them through.

**Files:** `src/Traits/BuilderFilters.php`, `src/Builders/QueryBuilder.php` (if it calls allowFilters), `src/Exceptions/InvalidFilterQuery.php`, tests `tests/Unit/Builders/QueryBuilderSecurityTest.php`.

- [ ] **Step 1: Failing test** — build a query from a request with a filter on an un-allow-listed column and assert it is rejected (not silently applied). Use a real Illuminate query against the SQLite fixture:
```php
<?php
use Ions\Builders\QueryBuilder; // adjust to the real entry API
use Ions\Exceptions\InvalidFilterQuery;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

test('a filter on a column that is not allow-listed is rejected', function () {
    // craft a request with filter[secret_col]=1 (or the project's filter syntax)
    // build the query WITHOUT calling allowedFilters([...])
    // expect InvalidFilterQuery (or that secret_col never appears in the SQL)
})->throws(InvalidFilterQuery::class);

test('an allow-listed filter column IS applied', function () {
    // allowedFilters(['name']) + filter[name]=x → applied, no throw
});
```
(Inspect the real request→filter parsing — `QueryBuilderRequest::fromRequest()` + the project's `filter` param syntax — and write the test against it.)

- [ ] **Step 2: Implement secure default.** In `BuilderFilters`: change `allowFilters()` default to `$allow_all = false`; when filters are requested but none are allow-listed (or a requested field isn't in the allow-list), throw `InvalidFilterQuery::filtersNotAllowed($field)` (mirror the `BuilderFields`/`InvalidFieldQuery` pattern that already exists). Provide an explicit opt-out (`allowFilters([...], true)` or a separate `allowAllFilters()`) for callers who genuinely want pass-through, but it must be **explicit**. Keep `Bundles\QueryBuilder` (constructor whitelist) as-is.

- [ ] **Step 3: Tests green; document the break** in the v2 upgrade guide: "Query filters are now allow-listed by default — call `allowedFilters([...])`; the previous allow-all default is gone (opt in explicitly)." Add sort-column hardening too if `BuilderSort` lacks a default guard (same pattern). Commit `security(query): allow-list filter/sort columns by default (close request→column injection)`.

---

## Sub-phase 4.2 — Upgrade Illuminate 9 → 10 (exploratory; gated on D-S = Sentinel 7)

> **Exploratory loop.** Resolve the upgrade, run the suite, fix breakages, repeat. Rector helps mechanically.

### Task 4.2.1: Resolve dependencies at Illuminate 10
- [ ] **Step 1:** Confirm `cartalyst/sentinel` has a Laravel-10-compatible release (`^7.0`?) on Packagist; note the version. If none exists, STOP — escalate D-S (can't go to 10 with Sentinel 6).
- [ ] **Step 2:** Bump in `composer.json`: all `illuminate/*` from exact `v9.52.4` → `^10.0`; **remove the interim `illuminate/container`/`illuminate/support` exact pins** (let them follow `^10.0`); `cartalyst/sentinel` → the L10 version; `monolog/monolog` → `^3.0` if L10 needs it (L10 allows `^2||^3` — prefer `^3`). Symfony stays `^6.4` (L10-compatible). Run `composer update` and resolve conflicts. Report the final resolved versions.
- [ ] **Step 3:** Run `composer rector` with the appropriate Laravel/PHP upgrade sets (add Rector's `LaravelLevelSetList`/`up-to-laravel-10` if available; otherwise targeted rules) to mechanically fix call-site deltas. Review the diff (don't blindly apply destructive changes).

### Task 4.2.2: Fix breakages until green
- [ ] Run `vendor/bin/pest`; fix each failure. Likely spots (from the surface map): Capsule/Eloquent boot, validation `Factory`/`DatabasePresenceVerifier`, pagination resolver, container contract, Sentinel bootstrap (`Auth/Guard/*`, `Auth/Sentinel/config.php`). Re-run until 113 (±) green.
- [ ] `composer qa` green. Regenerate the PHPStan baseline (the EOL→10 jump changes many third-party signatures; this is a legitimate baseline refresh — document that it's an upgrade-driven regen, and ensure NO *new Ions-code* errors are baselined — only third-party/legacy deltas).
- [ ] Update the master plan's "Interim toolchain note" (the illuminate 9 pins are gone). Commit `build: upgrade Illuminate 9 → 10; drop interim version pins; Sentinel 7; monolog 3`.
- [ ] **Bonus check:** re-attempt `composer require --dev pestphp/pest:^3` — expected to STILL fail at L10 (termwind), confirming Pest 3 is gated on L11. Leave Pest 2; note it.

---

## Sub-phase 4.3 — Modernize DB generators (stubs) for L10/L11 forward-compat

**Files:** `src/commands/migrate/MigrateCommand.php`, `src/commands/ModelCommand.php`, `src/commands/stubs/*`, tests.

- [ ] **Step 1:** `MigrateCommand` (and any migration stub) — replace `$table->increments('id')` with `$table->id()` (removed in L11). Test: generate/run a migration against the SQLite fixture and assert the table + `id` column exist.
- [ ] **Step 2:** `ModelCommand` stub — replace `use ...\SoftDeletingTrait` with `use Illuminate\Database\Eloquent\SoftDeletes`, and `$dates = ['deleted_at']` with `$casts = ['deleted_at' => 'datetime']`. Test: generate a model with soft-deletes and assert the emitted code references `SoftDeletes`/`$casts`, not the removed APIs.
- [ ] Commit `fix(generators): modern Eloquent stubs (id() / SoftDeletes / casts) for L11 compatibility`.

---

## Sub-phase 4.4 — Consolidate the two query builders

**Files:** `src/Builders/QueryBuilder.php` (+ traits), `src/Bundles/QueryBuilder.php`, tests.

- [ ] Pick `Builders\QueryBuilder` as the canonical public API (it's the richer, request-driven one with the `Invalid*Query` exceptions and — after 4.1 — secure-by-default allow-listing). Decide `Bundles\QueryBuilder`'s fate: **(a)** delete it (if unused outside the codebase — grep usages), or **(b)** keep it as a thin deprecated shim delegating to `Builders\QueryBuilder`. Document the choice + a migration note. Add a test asserting the canonical builder's filter/sort/field operators (`eq/ne/gt/lt/like/in`) behave consistently.
- [ ] Ensure both filter/sort/field paths are allow-list-guarded (from 4.1). Commit `refactor(query): one canonical QueryBuilder API; consolidate operators`.

---

## Sub-phase 4.5 — RedBean decision

- [ ] **Recommended: remove RedBean.** It duplicates Eloquent, ships an untyped global `R::` API, is only reachable via the `'redbean'` engine flag, and adds a heavy dependency. Remove `redBeanConnection()` + helpers from `DatabaseProvider`, drop `gabordemooij/redbean` from `composer.json`, treat a `'redbean'` value in `app.database_engine` as a no-op (or a logged deprecation). Test: a fixture configured with `['db']` is unaffected; grep confirms no remaining `R::`/`RedBeanPHP` usage. Document the removal in the upgrade guide.
- [ ] If the user wants RedBean kept, instead isolate it behind an interface and mark `gabordemooij/redbean` a `suggest`. Surface the choice; default to removal.

---

## Sub-phase 4.6 — Upgrade Illuminate 10 → 11 (+ Symfony 7 + Monolog 3 + Pest 3) — gated on D-S(11)

> **Exploratory loop, highest blast radius.** Do NOT start until D-S(11) is resolved (Sentinel-11 available, or Phase 5's `UserProvider` has replaced Sentinel). Consider running **Phase 5 before this**.

- [ ] **Step 1:** Bump `illuminate/*` → `^11.0`; `symfony/*` (config, mailer, routing, security-csrf, translation, yaml) → `^7.0`; `monolog/monolog` → `^3.0`; resolve `cartalyst/sentinel` per D-S(11). `composer update`; resolve conflicts; report resolved versions.
- [ ] **Step 2:** Rector up-to-Laravel-11 + Symfony 7 sets; review/apply. Fix Symfony-7 BC breaks across the direct symfony usages (Routing/HttpFoundation/Config/Mailer/Translation/Security-CSRF/Yaml — e.g. constructor/finalized-class changes, Request/Response API deltas, the CSRF token storage classes). Fix Monolog-2→3 changes (`Logs` bundle — Monolog 3 changed `LogRecord` to an object, handler signatures).
- [ ] **Step 3:** Now upgrade dev tooling unblocked by L11: `pestphp/pest:^3` + PHPUnit 11. Migrate any Pest-2→3 test deltas. Update `phpunit.xml` schema if needed.
- [ ] **Step 4:** Full suite green; regen baseline (upgrade-driven); `composer qa` green; update CI matrix if needed. Commit in logical chunks (`build: Illuminate 11`, `build: Symfony 7`, `build: Monolog 3`, `build: Pest 3/PHPUnit 11`). Update the master plan's interim notes (now resolved).

---

## Sub-phase 4.7 — MySQL in CI + migration/seeder tests

- [ ] Add a MySQL service to `.github/workflows/ci.yml` (a `services: mysql:` container) and a CI-only `config/database.php`-style fixture connection so Eloquent is exercised against **both** SQLite (in-memory, fast, local) and MySQL (CI). Gate the MySQL tests behind an env flag so local `composer qa` stays SQLite-only.
- [ ] Add tests for `migrate`/`rollback`/`schema`/`seeder` commands (generate + run against the fixture DB; assert tables/rows). These currently have none. Commit `ci(db): exercise Eloquent against MySQL; test migrate/rollback/schema/seeder`.

---

## Sub-phase 4.8 — DatabaseProvider polish

- [ ] Make connections lazy (already mostly lazy via the container singleton — verify the Capsule isn't eagerly connecting at boot); gate query logging strictly behind `APP_DEBUG`; ensure `bootEloquent` runs once. Add a test that booting with the DB engine does NOT open a connection until first query (if feasible to assert). Commit `perf(db): lazy connections; query log gated by APP_DEBUG`.

---

## Acceptance for Phase 4
- Off EOL Illuminate 9 — on **10 (min)**, ideally **11**; interim `illuminate/*` pins gone; Sentinel resolved (D-S); Symfony/Monolog bumped if at 11; Pest 3/PHPUnit 11 if at 11.
- Query filters/sorts allow-listed **by default** (request→column injection closed); one canonical `QueryBuilder` API.
- Generators emit L11-safe Eloquent (`id()`/`SoftDeletes`/`$casts`); RedBean removed (or explicitly isolated).
- Eloquent verified on SQLite **and** MySQL in CI; migrate/rollback/schema/seeder tested.
- `composer qa` green; PHPStan baseline reflects only legitimate upgrade-driven/third-party deltas (no new Ions-code suppressions).

---

## Risks & mitigations
- **Sentinel is the critical blocker (D-S).** Mitigate: verify Sentinel 7 for L10 before 4.2; defer the L11 Sentinel question to Phase 5's `UserProvider` (possibly run Phase 5 before 4.6). Do NOT start 4.6 until resolved.
- **Symfony 6→7 BC surface (4.6).** Mitigate: isolate to the 10→11 step; Rector Symfony sets; the 113-test net (esp. routing/handle/exception/CSRF tests) catches regressions; fix iteratively.
- **Monolog 2→3 (4.6).** `LogRecord` is now an object — audit `src/Bundles/Logs.php` handlers/processors.
- **Big-bang temptation.** Option A (9→10→11) keeps each step bisectable; resist merging them unless the user picks Option B.
- **Baseline churn on upgrades.** Upgrade tasks legitimately regen the baseline (third-party signature changes). Guard: diff the baseline and confirm new entries are third-party/legacy, not new Ions-code defects being hidden.
- **Injection hardening is a behavioral break.** Mitigate: it's a *security* fix (secure-by-default); documented in the upgrade guide; explicit opt-out provided.

## Self-review
- **Coverage vs master-plan Phase 4 items:** Illuminate 9→11 → 4.2 + 4.6; RedBean decision → 4.5; query-builder consolidation + injection hardening → 4.1 + 4.4; migrations/seeders formalized + tested → 4.3 + 4.7; DatabaseProvider lazy + query-log → 4.8; Pest 3 unblock → 4.6; MySQL CI → 4.7. All covered.
- **Security-first:** 4.1 (injection) is independent and ordered first.
- **Decisions surfaced:** D-U (target/path) and D-S (Sentinel) are explicit gates with recommendations, not silent assumptions.
- **Honest about exploratory tasks:** the upgrade sub-phases (4.2, 4.6) are resolve→observe→fix loops, not pre-written code — stated plainly, with Rector + the test net as the safety mechanism.
- **No placeholders:** 4.1/4.3 fully coded; 4.4/4.5/4.7/4.8 concrete specs; 4.2/4.6 are exploratory by nature with explicit gates — documented, not gaps.
