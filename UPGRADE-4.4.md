# Upgrading to 4.4

4.4.0 is additive for most hosts. The headline work is the Laravel-standard
host-root `database/` layout (migrations, seeders, factories, schema dumps and
backups under one `database/` tree), the `Database\Factories\` /
`Database\Seeders\` namespaces, a retargeted `make:model`, and JWT refresh-token
rotation with family reuse detection. These are catalogued in the
[CHANGELOG 4.4.0 section](CHANGELOG.md#440---2026-06-11), with full guides in
[docs/factories.md](docs/factories.md), [docs/console.md](docs/console.md) and
[docs/auth.md](docs/auth.md). This document covers only the behavior changes you
may need to act on.

No new composer dependencies install with this upgrade.

The security fixes below (path-traversal arbitrary file deletion, accurate
`Retry-After`) also shipped as the **4.3.1** patch; if you already upgraded to
4.3.1 they are unchanged here.

## Behavior changes

### Host-root `database/` takes precedence over `{app|src}/Database`

Since 4.4, `Path::database()` resolves the Laravel-standard host-root
`database/` tree (`migrations/`, `seeders/`, `factories/`, `schemas/`,
`backups/`) whenever `{root}/database` exists — and it **wins** over the legacy
`{app|src}/Database`. `MigrateCommand`, `SeederCommand`, `MakeFactoryCommand`,
`DumpCommand` and `SchemaCommand` all key off this.

**Action:**

- **Hosts with no `database/` directory** — nothing changes; the legacy
  `{app|src}/Database` layout stays the byte-identical fallback.
- **Hosts that adopt `database/`** — move your migrations, seeders, factories
  and schema dumps into `database/{migrations,seeders,factories,schemas}` (the
  legacy capitalized subfolder names map onto these lowercase directories).
- **Dual-directory hosts** — if both `database/` and a legacy
  `{app|src}/Database` exist, **`database/` wins** and the legacy directory is
  silently ignored by path resolution (it is left untouched on disk, not
  deleted). `ions doctor` now reports this with a `dual_database_dirs` warning;
  consolidate into `database/` and remove the unused legacy directory.

### `Database\Factories\` / `Database\Seeders\` namespaces

`HasIonsFactory` resolves a model's factory in this order: an explicit
`protected static string $factory` (wins), then the top-level
`Database\Factories\{Model}Factory` (the 4.4 layout), then the 4.2
`{ModelNamespace}\Factories\{Model}Factory` fallback. `make:factory` and
`make:seeder` generate into `database/factories/` and `database/seeders/` on the
new layout.

**Action:** hosts adopting the `database/` layout must register the **exact
sub-namespace mappings** in their composer.json `autoload.psr-4` (not a single
`"Database\\": "database/"` umbrella, which would not match the
`Database\Factories` / `Database\Seeders` class names):

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
}
```

Then run `composer dump-autoload`. Hosts on the legacy layout keep
`App\Factories` / `App\Database\Seeders` and need no composer change.

### `make:model` targets `app/Models`; DB introspection removed

`make:model` now generates models into `app/Models` (namespace `App\Models`,
via `Path::src('Models/…')` so the `src/`→`app/` fallback is preserved) on a
shared `GeneratorCommand` base that adds name validation and a `--force`
overwrite guard. The stub uses the `Ions\Database\HasIonsFactory` trait live,
and a new `--factory` flag also generates the matching
`Database\Factories\{Name}Factory`.

The command **no longer introspects the database** to auto-fill
`$table`/`$fillable`/`$hidden` from a live schema — the stub ships placeholder
properties for you to fill in.

**Action:** if you scripted `make:model` expecting the old target location or
the auto-filled columns, update those expectations: generated models now live in
`app/Models` and carry stub properties only.

### `Jwt::refresh()` return shape changed `string` → `array`

`Ions\Security\Jwt::refresh()` now performs token **rotation**: it revokes the
presented refresh token and re-issues **both** a new access token and a new
refresh token (same family), returning
`array{access: string, refresh: string}` instead of a single access-token
string.

**Action:** update any direct caller of `Jwt::refresh()`:

```php
// before (4.3)
$access = $jwt->refresh($refreshToken);

// after (4.4)
$tokens  = $jwt->refresh($refreshToken);
$access  = $tokens['access'];
$refresh = $tokens['refresh'];
```

### `POST /api/auth/refresh` now returns a new `refresh_token`

The refresh endpoint returns the rotated `refresh_token` alongside
`access_token`. Because the old refresh token is revoked on every refresh,
**clients must store the new `refresh_token` after each call** and present it on
the next refresh. Replaying a rotated (already-used) refresh token is treated as
a breach and **revokes the entire token family** — every sibling refresh token
in that lineage is invalidated, forcing a fresh login.

**Action:** update API clients to persist the `refresh_token` from each refresh
response. Pre-4.4 refresh tokens (issued without a family id) still refresh and
join the family-aware scheme on their first rotation, so no forced re-login is
required at the upgrade boundary.

### Custom `RevocationStore` implementations must add two methods

`Ions\Security\RevocationStore` gained `revokeFamily(string $fid, int
$ttlSeconds): void` and `isFamilyRevoked(string $fid): bool` for family-based
revocation. The bundled `ArrayRevocationStore` and `CacheRevocationStore`
implement them.

**Action:** if you ship a custom `RevocationStore`, implement the two new
methods (a no-op `revokeFamily` plus a `false`-returning `isFamilyRevoked`
disables family revocation but keeps the store compiling).

## Security fixes (also released as 4.3.1)

### Path-traversal arbitrary file deletion

A crafted filename passed to `IonUpload::update()`/`remove()` or
`IonDisk::delete()`/`deleteDirectory()` could escape the uploads/disk root and
delete arbitrary files. Deletions are now constrained to a single path segment
(`basename` + rejection of `.`/`..`) and a `realpath` containment check against
the resolved root (a derived uploads root is used when no local root is
configured). No action needed — the fix is internal.

### Accurate `Retry-After`

The rate-limit middleware and the per-email forgot-password throttle now emit
the true remaining window in `Retry-After` instead of the full window, so
clients back off for the correct duration. No action needed.

## New in 4.4

Beyond the behavior changes above, 4.4 is the database-layout release: a single
host-root `database/` tree for migrations, seeders, factories, schema dumps and
backups; the `Database\Factories\` / `Database\Seeders\` namespaces with
Laravel-parity factory resolution; `make:model` into `app/Models` with a
`--factory` companion flag; and JWT refresh rotation with family reuse
detection. See the [CHANGELOG 4.4.0 section](CHANGELOG.md#440---2026-06-11),
[docs/factories.md](docs/factories.md), [docs/console.md](docs/console.md) and
[docs/auth.md](docs/auth.md) for the full reference.
