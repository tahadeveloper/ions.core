# Upgrading to 4.2 (draft — release notes assembled at release)

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
hooks keep raw `method_exists` detection, unchanged. See
[docs/controllers.md](docs/controllers.md).
