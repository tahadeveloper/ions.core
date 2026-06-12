# Upgrading to 4.6

4.6 contains a single **breaking change**: the database folder convention was
swapped to match Laravel. Everything else is unchanged. There are no new
composer dependencies.

## Breaking: migrations live in `database/migrations`, dumps in `database/schemas`

The Ions convention was inverted from Laravel. Until 4.5, `make:schema` wrote
**runnable migration files** to `database/schemas/` and `schema:dump` wrote
**schema dumps** to `database/migrations/`. The two roles are now swapped to
match the Laravel layout:

| Artifact | Created by | Read by | Directory (4.6) | Directory (≤4.5) |
|----------|------------|---------|------------------|-------------------|
| Runnable migrations (`*.php`) | `make:schema` | `migrate`, `schema:dump --prune` | `database/migrations/` | `database/schemas/` |
| Schema dumps (`*_schema.dump`) | `schema:dump` | `migrate:rollback` | `database/schemas/` | `database/migrations/` |

Only the directories each command points at changed. **Unchanged:** the
`App\Database\Schema` namespace written into generated stubs, the
`Path::database()` casing-normalization map values, and the legacy
`{app|src}/Database` fallback chain — a legacy host (no host-root `database/`
dir) now reads runnable migrations from `{app|src}/Database/migrations`.

### Action

Move your existing files:

```bash
# Runnable migration classes → database/migrations/
mkdir -p database/migrations
git mv database/schemas/*.php database/migrations/

# Any previously-written schema dumps → database/schemas/
git mv database/migrations/*_schema.dump database/schemas/ 2>/dev/null || true
```

Legacy `{app|src}/Database`-layout hosts: move runnable migration classes from
`{app|src}/Database/Schema` to `{app|src}/Database/migrations`.

After moving, `php bin/ions migrate` discovers the same classes from the new
`database/migrations/` directory; `php bin/ions schema:dump` now writes into
`database/schemas/`, and `php bin/ions migrate:rollback` replays from there.
