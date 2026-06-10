# Phase 9 — Conventions & DX II Implementation Plan (→ 4.2.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing it (the dispatch prompt carries full grounded detail). Gates throughout: `php83 vendor/bin/pest` green (788+), PHPStan level-5 main + level-8 core both 0; new PSR-4 code `strict_types` + level 8; TDD + per-sub-phase two-stage review + merge-as-you-go (the Phase 8 cadence). Run everything via `php83`.

**Goal:** Make the framework conventional and pleasant: `app/` layout, effortless view returns, real controller DI + a documented lifecycle, a cron scheduler, frontend scaffolding, deploy configs, and a best-practices guide — released as 4.2.0.

**Architecture:** Each sub-phase is additive and independently shippable. The dispatcher (`Kernel::instanceTheController`) gains two capabilities (renderable returns, container construction + method injection); everything else is new classes/commands/docs following the 8.x facade/provider/generator conventions.

**Tech Stack:** PHP 8.3, Twig (FilesystemLoader namespaces), Illuminate container, `dragonmantank/cron-expression` ^3 (new dep, 9.4 only), Vite/Vue 3 (generated host files only — no PHP dep).

**Spec:** `docs/superpowers/specs/2026-06-10-phase9-conventions-dx-design.md` (decisions D9-1..D9-4 resolved with the user).

---

## 9.1 `app/` convention flip

**Files:** Modify `src/Bundles/Path.php` (the `src/`→`app/` resolution methods: `src()`, `api()`, `database()` — reverse check order, keep fallback). Move `skeleton/src/` → `skeleton/app/` (+ `skeleton/composer.json` PSR-4 `App\ => app/`, README/docs mentions). Modify `src/Foundation/Doctor.php` (new WARN check `dual_app_dirs` when both `app/` and `src/` exist). New fixture `tests/fixtures/app-applayout/` (app/-only minimal host) or temp-dir tests. Docs: CLAUDE.md, docs/skeleton.md, docs/config.md, UPGRADE-4.2.md (new file, precedence note).

- [ ] Tests first: `Path::src()` prefers `app/` when both exist; falls back to `src/` when only it exists; app/-only layout boots + serves `/ping`-style route; generators (`make:job`) emit into `app/` on an app/-layout host; doctor warns on dual dirs.
- [ ] Implementation: flip order in Path (one place per method); doctor check; skeleton move (git mv, adjust composer.json/PSR-4/README/phpunit paths); UPGRADE-4.2.md skeleton.
- [ ] Full gates; commit `feat(path): app/ checked before src/ (fallback kept); skeleton moves to app/`.

**Acceptance:** existing fixture suite untouched (src/ fixtures still pass verbatim); app/-layout test matrix green; doctor dual-dir WARN tested.

## 9.2 Views: namespaces + `view()` helper + controller-relative

**Files:** New `src/View/View.php` (final renderable: `template`, `data`, `render(): string` via the shared env). Modify `src/View/ViewFactory.php` (register `config('app.twig.paths')` namespaces on the FilesystemLoader once, in the singleton build). Modify `src/helpers.php` (add `view(string $template, array $data = []): Ions\View\View`; dots→`/`, leading `@ns.` → `@ns/`). Modify `src/Foundation/Kernel.php` action-return handling (where Response/Responsable returns are converted): `instanceof Ions\View\View` → 200 HTML Response with `render()` body. Modify `src/Foundation/BaseController.php`: `protected function view(string $name, array $data = []): Ions\View\View` + `protected string $viewPath = ''` override; folder derivation: controller FQCN path under `Http\Controllers\` → kebab-case dirs; root controller → short name minus `Controller`, kebab-cased. Fixture: namespaced views dir + routes returning `view()`/`$this->view()`. Docs: docs/config.md (`app.twig.paths`), docs/controllers.md (created in 9.3 — view-return part can land here first in views docs then merged).

- [ ] Tests first (unit): View renderable; helper dot/namespace translation; ViewFactory namespace registration (assert loader paths). (Feature): `return view('users.index')` through `Kernel::handle()` → 200 + rendered body; `@admin` namespace render; `$this->view('index')` from a nested fixture controller; `$viewPath` override; ApiController has no `view()`.
- [ ] Full gates; commit `feat(view): namespaced view roots + view() renderable returns + controller-relative $this->view()`.

**Acceptance:** all spec 9.2 acceptance bullets test-covered; Twig singleton (8.1) preserved (assert same env instance).

## 9.3 Controller lifecycle + DI

**Files:** Modify `src/Foundation/Kernel.php` `instanceTheController`: build via `app()->make($class)`; action invocation resolves params (Request → current request; route-placeholder names → scalar values; object type-hints → `app()->make()`; defaults respected); insert new hooks around existing ones (order: `_initState → _loadInit → _loadedState → boot → beforeAction → action → afterAction → _endState`); `beforeAction` returning Response short-circuits (skip action, still run `_endState`); `afterAction` returning Response replaces; `middleware(): array` read pre-dispatch and resolved fail-closed via the existing per-route resolution (4.1 policy), executed in the same pipeline position as route middleware. Modify `src/Foundation/BaseController.php` + `ApiController.php`: no-op default hook implementations + docblocks (interface additions only if BC-safe — prefer duck-typed `method_exists` like the legacy hooks). New `docs/controllers.md` (full cycle: legacy + new hooks, DI rules, view returns, per-controller middleware, examples). Fixture: order-recording controller.

- [ ] Tests first: constructor injection (service bound in fixture provider); zero-arg controller byte-identical behavior; action injection matrix (Request, route param by name, service type-hint, mixed, default values); hook firing order recorded; `beforeAction` short-circuit (action not run, 403 path); `afterAction` decorates; `middleware()` enforced + unresolvable alias fails closed; legacy hook tests untouched.
- [ ] Full gates; commit `feat(http): container-built controllers, action method injection, boot/beforeAction/afterAction/middleware() lifecycle`.

**Acceptance:** spec 9.3 bullets; docs/controllers.md is the single canonical lifecycle reference (other docs link to it).

## 9.4 Scheduler

**Files:** New `src/Schedule/Scheduler.php` (registry: `command(string $signature): Task`, `call(callable, ?string $name): Task`, `dueTasks(DateTimeImmutable $now): list<Task>`, `runDue(...)`), `src/Schedule/Task.php` (fluent: `cron()`, `everyMinute()`, `everyFiveMinutes()`, `hourly()`, `daily()`, `dailyAt('03:00')`, `weekly()`, `monthly()`, `name()`, `withoutOverlapping(int $ttl = 3600)`; `isDue(now)`, `run()` — command tasks run through the console kernel, callables invoked; output/Throwable → `var/logs/schedule.log`, failures isolated). New `src/Support/Schedule.php` facade (resolves lazy `'schedule'` binding from new `ScheduleProvider`; provider also invokes `App\Schedule::boot($scheduler)` if the host class exists — lazily, on first resolve). New commands `src/commands/ScheduleRunCommand.php` (`schedule:run`), `ScheduleListCommand.php` (`schedule:list`: name, expression, next run via cron-expression `getNextRunDate`) + FRAMEWORK_COMMANDS. Modify the `/cron/schedule` kernel route handler to drive the same scheduler (find current wiring — `App\Schedule::boot` route target — and adapt BC: if host `App\Schedule::boot` has the legacy zero-arg signature, call it legacy-style; if it accepts `Scheduler`, pass it). Composer: add `dragonmantank/cron-expression: ^3`. Skeleton: `app/Schedule.php` example. Docs: new docs/scheduler.md + README row + config keys if any.

- [ ] Tests first: Task fluent → expression mapping (each helper); `isDue` against frozen now (due + not due); `withoutOverlapping` lock blocks concurrent run (array cache); failing callable doesn't stop the next task + logs; `schedule:run` executes due-only (CommandTester); `schedule:list` table; web-cron route parity (Kernel::handle on the route → due tasks run); legacy `App\Schedule::boot` zero-arg BC.
- [ ] Full gates; commit `feat(schedule): fluent cron scheduler — schedule:run/list, web-cron parity, withoutOverlapping`.

**Acceptance:** spec 9.4 bullets; crontab line documented; zero hot-path (binding lazy, route only resolves on hit).

## 9.5 Assets: install:vue / install:assets / Twig functions

**Files:** New commands `src/commands/InstallVueCommand.php` (`install:vue`), `InstallAssetsCommand.php` (`install:assets`) + stub files under `src/commands/stubs/assets/` (package.json.stub, vite.config.js.stub, app.js.stub, App.vue.stub, app.css.stub) — refuse-unless-`--force` per GeneratorCommand conventions (these write MULTIPLE files: guard each, report). New `src/View/AssetExtension.php` (Twig extension: `vite(entry)` — reads `public/build/manifest.json`, emits module script + CSS links; hot mode when `public/hot` exists → dev-server URLs + `@vite/client`; `asset(path)` — `rtrim(app.app_url)/path?v=filemtime`, missing file → no buster, never throws). Register the extension in ViewFactory's env build. Docs: docs/assets.md (scaffold contents, dev vs build flow, vite()/asset() reference) + README row + skeleton README mention.

- [ ] Tests first: command file-sets created with expected content (parse generated package.json as JSON; vite.config contains manifest+outDir); refusal + `--force`; `vite()` manifest-mode output (fixture manifest.json), hot-mode output (fixture hot file), missing-manifest → clear Twig-safe error comment; `asset()` URL + buster + missing-file fallback; extension registered in shared env. No node required by tests.
- [ ] Full gates; commit `feat(assets): install:vue/install:assets scaffolds + vite()/asset() Twig functions`.

**Acceptance:** spec 9.5 bullets; PHP test suite fully green without node.

## 9.6 Deploy configs

**Files:** New `skeleton/public/.htaccess` (rewrite existing-file passthrough → index.php; deny `.\*` dotfiles). New `docs/deploy.md` (nginx server block: root public/, try_files, deny var/config/.env, gzip/static caching; Apache vhost; PHP-FPM pool note; TLS-proxy caveat cross-ref; worker-mode pointer; checklist ending `ions optimize && ions doctor`). Modify skeleton README (serve section links deploy.md). Extend `tests/Feature/SkeletonTest.php`: .htaccess exists + contains the rewrite to index.php.

- [ ] Tests first (the .htaccess presence/content assertion); write files; verify docs claims against `skeleton/public/index.php` reality.
- [ ] Full gates; commit `feat(deploy): skeleton .htaccess + docs/deploy.md (nginx/apache, hardening, checklist)`.

## 9.7 Best practices + README tour + release 4.2.0

**Files:** New `docs/best-practices.md` (structure on app/, thin controllers + FormRequest, providers + DI, typed config, jobs/events/notifications idioms, testing kit + factories, security checklist, performance checklist, deploy pointer). README: quick-tour code sample refresh (app/ + `return view()` + DI + scheduler). Release: CHANGELOG `[4.2.0]` assembled NOW (not deferred), UPGRADE-4.2.md finalized (app/ precedence + anything 9.x flagged), version compare links, final whole-branch review, merge, push, tag `4.2.0` (no `v`) — tag push only after user confirmation (established protocol).

- [ ] docs + changelog assembly; accuracy review (claim-by-claim, the 8.7 bar); gates; merge; push; tag prepared locally.

---

## Risks (from spec, carried)

- app/ precedence flip on dual-dir hosts → UPGRADE-4.2 + doctor WARN (9.1).
- Container-built controllers: construction failures change exception type → pin zero-arg path; clear messages.
- Action injection collisions → scalars-by-route-name vs objects-by-type rule, documented.
- Vite ecosystem drift → major-range pins, "starting point" framing.
- New dep (cron-expression) → MIT, dependency-free, ecosystem standard.

## Self-review

Spec coverage: 9.1→Task 9.1 ✓, 9.2 (namespaces/helper/bridge/controller-relative) ✓, 9.3 (DI/hooks/middleware()/docs page) ✓, 9.4 (fluent/withoutOverlapping/run/list/web-cron/BC) ✓, 9.5 (two installers/vite()/asset()/hot) ✓, 9.6 ✓, 9.7 (best-practices/README/release) ✓. Decisions D9-1..4 honored. No placeholders — execution-time grounding is explicit and intentional per the master-plan cadence; every task names exact files, APIs, and its test list.
