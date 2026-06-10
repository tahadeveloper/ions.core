# Phase 9 — Conventions & DX II (Design → 4.2.0)

Validated with the user 2026-06-10. All items are **additive**; release framing
is **4.2.0**. Execution follows the Phase 8 cadence: one sub-phase at a time,
TDD, two-stage subagent review, merge-as-you-go; gates: `php83 vendor/bin/pest`
green, PHPStan level-5 main + level-8 core both 0, new code `strict_types`.

## Decisions (confirmed by user)

- **D9-1: `app/` becomes the preferred host folder.** `Path` checks `app/`
  first, `src/` remains a working fallback (no breakage). Skeleton, generators
  and docs follow. Not a hard removal (that would be 5.0).
- **D9-2: View "multi folder" = namespaced view roots** (Twig-native
  namespaces), configured per name, alongside the default `views/`.
- **D9-3: View returns ship BOTH styles** — controller-relative
  `$this->view('index')` and global `view('users.index')` dot-notation helper.
- **D9-4: Assets ship Vite + Vue scaffold (`install:vue`) AND a plain
  no-build variant (`install:assets`).**

## 9.1 `app/` convention flip

- `Path::src()` (and the api/database derivatives) check `{root}/app` first,
  then fall back to `{root}/src` — the reverse of today's order. The fallback
  is preserved verbatim so every existing host keeps working.
- `skeleton/src/` → `skeleton/app/` (namespace stays `App\`, PSR-4 maps
  `App\ => app/`). Generators already resolve through `Path::src()` so they
  follow automatically; their stubs/namespaces are unchanged.
- Docs + CLAUDE.md updated. UPGRADE-4.2 note: a host having **both** `app/`
  and `src/` now resolves to `app/` (previously `src/` won) — rename or
  consolidate before upgrading.
- Acceptance: fixture variant with `app/`-only layout boots and serves;
  generators emit into `app/` on that layout; a both-dirs fixture documents
  the precedence in a test.

## 9.2 Views: namespaces, dot-notation helper, controller-relative returns

- **Namespaces:** `config('app.twig.paths')` map — `['admin' =>
  'views/admin', 'mail' => 'views/mail']` — registered on the Twig
  `FilesystemLoader` as named namespaces; templates address them as
  `@admin/users/index.twig`. Relative entries resolve from the host root;
  absolute paths allowed (packages). The default `views/` root keeps working
  untouched. Namespace registration happens once in the shared `view.env`
  (8.1 singleton preserved).
- **`view()` helper** (function_exists-guarded): `view('users.index', $data)`
  → renders `views/users/index.twig` (dots → directory separators;
  `@namespace` passthrough: `view('@admin.users.index')` works). Returns an
  `Ions\View\View` renderable object (template + data), NOT a string —
  rendering happens when the dispatcher converts it.
- **Dispatcher bridge:** `instanceTheController`'s action-return handling
  converts an `Ions\View\View` return into a 200 HTML `Response` (same place
  Response/JsonResponse/Responsable returns are handled today). `return
  view('users.index', [...])` from any action just works.
- **Controller-relative:** `BaseController::view(string $name, array $data =
  [])` resolves `views/{controller_dir}/{name}.twig` where `controller_dir`
  is the controller's path under `Http/Controllers` (kebab/snake-cased,
  `HomeController` in `Http/Controllers/Users/` → `users/`); a root-level
  controller maps to the controller's own short name minus `Controller`
  (`UsersController` → `users/`). Overridable via `protected string
  $viewPath`. `ApiController` does NOT get it (APIs return JSON).
- Acceptance: namespace render via config; `return view('x.y')` end-to-end
  through `Kernel::handle()`; `$this->view()` resolution for nested + root
  controllers + `$viewPath` override; helper + renderable unit-tested.

## 9.3 Controller lifecycle + dependency injection

- **Constructor DI:** `instanceTheController` builds controllers via
  `Kernel::app()->make($class)` instead of `new $class` — type-hinted
  constructor dependencies resolve from the container. BC: zero-arg
  controllers behave identically; `BaseController`/`ApiController`
  constructors keep their current bodies.
- **Action method injection:** when dispatching the action, resolve its
  parameters: `Request` (and subclasses) → the current request; parameters
  whose names match route placeholders → the route values (scalars);
  remaining type-hinted object parameters → `app()->make()`. Untyped/extra
  params keep today's behavior. Applies to both `Controller::method` styles
  and closure `_controller`s where cheap.
- **Lifecycle:** existing hooks keep firing in the exact current order
  (`_initState → _loadInit → _loadedState → action → _endState`) — untouched,
  BC. New documented hooks slot around them:
  - `boot()` — runs after framework wiring (after `_loadedState`), before the
    action; the "easy boot" hook; supports method injection.
  - `beforeAction(Request $request): ?Response` — may short-circuit by
    returning a Response (auth/permission checks).
  - `afterAction(Request $request, Response $response): ?Response` — may
    replace/decorate the response.
  - `middleware(): array` — per-controller middleware aliases/instances,
    merged after route middleware, resolved fail-closed like per-route
    middleware (4.1 policy).
- One docs page (`docs/controllers.md`) documenting the FULL cycle (old +
  new hooks, DI rules, view returns, middleware) — supersedes scattered
  references.
- Acceptance: constructor + action injection tests (service, request, route
  params, mixed); each new hook fires in documented order (order-recording
  fixture controller); `beforeAction` short-circuit; `afterAction`
  decoration; `middleware()` enforcement + fail-closed; legacy hooks
  unchanged (existing tests untouched).

## 9.4 Scheduler

- `Ions\Schedule\Scheduler` + `Ions\Support\Schedule` facade. Fluent API:
  `Schedule::command('emails:send')->daily()`,
  `Schedule::call(fn () => ..., 'name')->everyFiveMinutes()`,
  `->cron('*/10 * * * *')`, `->hourly() / ->daily() / ->dailyAt('03:00') /
  ->weekly() / ->monthly() / ->everyMinute() / ->everyFiveMinutes()`,
  `->withoutOverlapping(?int $ttl)` (cache-lock via the shared cache),
  `->onSuccess()/->onFailure()` callbacks optional-scope (include only if
  small). Cron parsing via `dragonmantank/cron-expression` (^3, MIT — the
  ecosystem standard).
- **Host definition point:** `App\Schedule::boot(Scheduler $schedule)` — the
  existing convention, now receiving the scheduler instance. Skeleton ships
  an example `app/Schedule.php`.
- **Runners:** `schedule:run` console command (for `* * * * * php bin/ions
  schedule:run`) executes all due tasks; the existing `/cron/schedule` web
  route keeps working and now drives the same scheduler (web-cron fallback).
  `schedule:list` shows tasks + next run times.
- Task output/errors logged to `var/logs/schedule.log`; a failing task never
  stops the others.
- Acceptance: due/not-due evaluation against a frozen "now"; overlap lock
  blocks a second run; `schedule:run` executes due-only; `schedule:list`
  output; web-cron route parity; failing task isolation.

## 9.5 Assets: `install:vue`, `install:assets`, Twig functions

- **`install:vue`:** writes into the host — `package.json` (vue ^3, vite,
  `@vitejs/plugin-vue`, scripts dev/build), `vite.config.js` (build →
  `public/build` with `manifest: true`, dev server config), `resources/js/
  app.js` + `resources/js/App.vue` example, `.gitignore` additions
  (`node_modules/`, `public/build/`). Refuses to overwrite existing files
  without `--force` (GeneratorCommand-style guard).
- **`install:assets`:** no-build variant — `resources/css/app.css`,
  `resources/js/app.js` published to `public/assets/` via an `assets:publish`
  copy step (or generated directly into public/ — decide at execution,
  simplest correct wins).
- **Twig functions** registered in the shared environment:
  - `vite('resources/js/app.js')` — emits `<script type="module">` (+ CSS
    `<link>`s) from `public/build/manifest.json`; when a Vite dev server is
    running (presence of `public/hot` file, Laravel-style), emits dev-server
    URLs + the HMR client instead.
  - `asset('css/app.css')` — `app.app_url`-based URL with `?v=filemtime`
    cache-busting; works for any file under `public/`.
- Acceptance: scaffold file-sets (content + lint where JS allows), refusal +
  `--force`, manifest-mode and hot-mode `vite()` output, `asset()` URL +
  cache-buster; nothing requires node to be installed for the PHP tests.

## 9.6 Deploy configs

- `skeleton/public/.htaccess`: front-controller rewrite (existing-file
  passthrough → `index.php`), deny dotfiles. Mentioned in skeleton README.
- `docs/deploy.md`: complete nginx server block (root → `public/`,
  `try_files → /index.php?$query_string`, deny `/var` `/config` `/.env`,
  static caching), Apache vhost example, PHP-FPM notes, the worker-mode
  pointer, TLS-proxy caveat (from 4.1), and a deploy checklist ending with
  `ions optimize && ions doctor`.
- Acceptance: .htaccess shipped in skeleton; docs reviewed for accuracy
  against the front controller's actual expectations.

## 9.7 Best practices & ergonomics docs

- `docs/best-practices.md`: recommended structure (app/ layout), thin
  controllers + FormRequests, services via providers + constructor DI, typed
  config accessors, events/jobs/notifications idioms, the testing kit
  (factories + fakes), security checklist (4.1 defaults, signed URLs,
  doctor), performance checklist (optimize, caches, N+1 detector),
  deployment pointer. Cross-linked from README top.
- README "quick tour" refresh showing the 4.2 ergonomics (app/, view
  returns, DI, scheduler) as the first-impression code sample.

## Sequencing

1. **9.1 app/ flip** (everything else's docs build on it) →
2. **9.2 views** → 3. **9.3 lifecycle/DI** (both touch the dispatcher; views
   first since lifecycle's `afterAction` interacts with view returns) →
4. **9.4 scheduler** → 5. **9.5 assets** → 6. **9.6 deploy** →
7. **9.7 best practices** → release 4.2.0 (UPGRADE-4.2: app/-precedence
   note; CHANGELOG assembled at the end, not deferred per-phase this time).

## Risks & mitigations

- **app/ precedence flips a both-dirs host** → UPGRADE note + doctor check
  (add a doctor WARN when both `app/` and `src/` exist).
- **Container-built controllers change construction failures** from fatal
  `new` errors to container exceptions → keep messages clear; tests pin the
  zero-arg path byte-identical.
- **Action injection ambiguity** (route param name colliding with a service
  type-hint) → route-param-by-name wins for scalars, container only for
  object type-hints; documented.
- **Vite scaffold drift** (frontend ecosystem moves fast) → pin only
  major-version ranges in the generated package.json; the scaffold is a
  starting point, documented as such.
- **Scheduler new dep** (`dragonmantank/cron-expression`) → MIT, zero
  transitive deps, the de-facto standard (Laravel uses it).
