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
method injection (route placeholders by name, services by type-hint); closures
taking the request — typed or as an untyped first parameter — are unchanged.

### Controller lifecycle hooks are duck-typed by name

New optional controller hooks (`boot()`, `beforeAction()`, `afterAction()`,
`middleware()`) are detected via `method_exists`, like the legacy underscore
hooks. If an existing controller defines a plain method with one of those
names (e.g. an action named `boot`), it will now be invoked as a lifecycle
hook — rename such methods. See [docs/controllers.md](docs/controllers.md).
