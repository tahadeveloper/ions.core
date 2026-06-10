# Upgrading to 4.2

4.2.0 is additive for almost every host — the new facilities (the `app/`
layout convention, `view()` renderable returns and namespaced view roots,
container-built controllers with method injection and lifecycle hooks, the
fluent cron scheduler, the `install:vue`/`install:assets` frontend scaffolds,
and the deploy configs) are catalogued in the
[CHANGELOG 4.2.0 section](CHANGELOG.md#420---2026-06-11), with full guides in
[docs/best-practices.md](docs/best-practices.md), [docs/views.md](docs/views.md),
[docs/controllers.md](docs/controllers.md), [docs/scheduler.md](docs/scheduler.md),
[docs/assets.md](docs/assets.md) and [docs/deploy.md](docs/deploy.md). This
document covers only the behavior changes you may need to act on.

One new composer dependency is installed automatically with the upgrade:
`dragonmantank/cron-expression` `^3` (the scheduler's cron parser — MIT,
no transitive dependencies). No action needed.

## Behavior changes

### Host layout: `app/` is now checked before `src/`

`Ions\Bundles\Path` resolves the host application code directory for
`Path::src()`, `Path::api()` and `Path::database()` — and therefore for
everything built on them: controller dispatch, attribute-route discovery,
provider/command auto-discovery, migrations/seeders and every `make:*`
generator.

Before 4.2 the order was `src/` first, `app/` fallback. In 4.2 it flips:

| Host root contains | 4.1 resolves to | 4.2 resolves to |
|---|---|---|
| only `src/` | `src/` | `src/` (unchanged — fallback preserved) |
| only `app/` | `app/` | `app/` (unchanged) |
| **both** `app/` and `src/` | `src/` | **`app/`** |
| neither (fresh host) | `app/` | `app/` (unchanged) |

**Who is affected:** only hosts carrying **both** directories at the root.
`src/`-only hosts are completely unaffected — the legacy fallback is preserved
verbatim. `app/`-only and fresh hosts already resolved to `app/`.

**Action (dual-directory hosts):** consolidate your application code into
`app/` (and update your composer PSR-4 mapping, e.g. `"App\\": "app/"`), or
remove the unused directory so a single layout remains. Until you do, `src/`
is silently ignored by path resolution. `ions doctor` flags this state with a
`dual_app_dirs` WARN.

The skeleton host application now ships its code in `app/` (`App\` PSR-4 maps
to `app/`).

### `app.twig.paths` string keys now mean namespaces

Before 4.2, array keys in `app.twig.paths` were ignored (every entry resolved
via `Path::views($value)`). In 4.2 a **string key** declares a view namespace
and its value resolves from the **host root** (absolute paths kept). Plain
numeric-key lists keep the old behavior verbatim. A host that accidentally
used string keys before 4.2 should switch those entries to a plain list or
update the values to host-root-relative paths.

### Closure routes: return normalization unified with controllers

Controller actions and closure routes now share one return normalizer
(`Ions\Http\ResponseNormalizer`). Additive: closure routes can now return an
`Ions\Http\Responsable` (previously only controller actions could — a closure
returning one fell through to the shared kernel response). `Response`, `View`
and null/void returns behave exactly as before. Closure routes also receive
method injection (route placeholders by name, services by type-hint). A
`Request`-typed closure parameter still receives the request anywhere in the
signature; an **untyped** first parameter also still does — **unless it is
named after a route placeholder**, in which case it now receives that
placeholder's value instead of the request (rename the parameter or type-hint
`Request` explicitly to keep the old behavior).

### Action method injection — argument BC

Controller actions were previously always invoked with exactly `[$request]`.
In 4.2 they are method-injected (`Ions\Http\ActionArgumentResolver`), and the
**placeholder-name match beats the untyped-first-param legacy rule**: on a
route like `/users/{id}`, `public function show($id)` received the `Request`
pre-9.3 but now receives the scalar placeholder value (`'42'`, or `42` when
the parameter is hinted `int`). If your action relied on the old positional
contract, type-hint the request (`show(Request $request)`) or rename the
parameter so it no longer collides with a placeholder. One further edge: an
action whose **first parameter is variadic** (`show(...$args)`) previously
received `[$request]` and now receives **nothing** — variadics stop argument
resolution.

### Controller lifecycle hooks are duck-typed by name (public methods only)

New optional controller hooks (`boot()`, `beforeAction()`, `afterAction()`,
`middleware()`) are detected by name on **public** methods. If an existing
controller defines a public method with one of those names (e.g. an action
named `boot`), it will now be invoked as a lifecycle hook — rename such
methods. Protected/private methods with those names are ignored (a host's
`protected boot()` helper does not break dispatch). The legacy underscore
hooks keep raw `method_exists` detection, unchanged. One exception: when a
route's **action** method is itself named `boot` (the legacy
`App\Schedule::boot` contract), it is dispatched once as the action and the
`boot()` hook is skipped — it never fires twice. See
[docs/controllers.md](docs/controllers.md).

### `/cron/schedule` web-cron: scheduler parity (opt-in via the boot signature)

The built-in `/cron/schedule` route now targets a framework controller that
inspects your `App\Schedule::boot()` signature at hit time:

- **Legacy zero-parameter `boot()` — nothing changes.** The class keeps the
  exact controller-string dispatch it always had (`boot()` IS the cron job).
  One introspection nuance: because the route now targets the framework
  controller, the request attributes report `_controller_name`
  `'WebCronController'` / `_method_name` `'run'` (previously
  `'Schedule'`/`'boot'`) — hosts reading those attributes should adjust.
- **New signature `boot(\Ions\Schedule\Scheduler $schedule)` — opt-in.** The
  route runs the due tasks of the new fluent scheduler (the same tasks
  `schedule:run` executes) and answers with a JSON summary
  `{"ran": n, "failed": n, "skipped": n}`. See
  [docs/scheduler.md](docs/scheduler.md).
- **No `App\Schedule` class:** the route now answers **404** (previously a
  500 from the failed controller resolution).

`schedule:run` additionally runs the new scheduler's due tasks before the
legacy `GO\Scheduler` `schedule.php` jobs (which keep working unchanged), and
now exits non-zero when a task fails. `schedule:list` is new. When migrating
legacy jobs to `App\Schedule::boot(Scheduler)`, remove each one from
`schedule.php` as you go — both registries run on every `schedule:run`, so a
job defined in both executes twice per tick. See
[docs/scheduler.md](docs/scheduler.md#legacy-compatibility).
