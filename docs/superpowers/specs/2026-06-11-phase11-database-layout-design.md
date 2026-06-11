# Phase 11 — Standard Database Layout & app/Models (Design → 4.4.0)

Validated with the user 2026-06-11 (design presented and approved "go"). All
items additive with the proven precedence-flip pattern (9.1); release framing
**4.4.0**. Execution: the established cadence (TDD, two-stage subagent review,
merge-as-you-go; gates `php83 vendor/bin/pest` green zero warnings, PHPStan
level-5 main + level-8 core both 0, new PSR-4 code strict_types).

## 11.1 Host-root `database/` directory

- New convention (Laravel-standard), sibling of `app/`/`config/`/`views/`:
  `database/migrations`, `database/seeders`, `database/factories`,
  `database/schemas`, `database/backups`, and `database/database.sqlite` as
  the documented default sqlite location.
- `Path::database($sub)` precedence: host-root `database/` **first** when the
  directory exists, legacy `{app|src}/Database` fallback otherwise (verbatim
  9.1 pattern — no existing host breaks). Sub-path mapping: the legacy layout
  used `{app|src}/Database/{Migrations?,Seeders?,Schema?...}` — GROUND the
  exact legacy subfolder names consumed by migrate/seeder/schema/dump
  commands and map each onto the new lowercase dirs (`migrations/`,
  `seeders/`, `schemas/`, `backups/`). The MIGRATE command and friends must
  consume the new layout transparently through Path.
- Doctor check `dual_database_dirs`: WARN when both host-root `database/` and
  legacy `{app|src}/Database` exist (consolidate guidance).
- Acceptance: a host with `database/migrations` runs `migrate` from it; a
  legacy-layout fixture keeps passing untouched; dual-dir doctor WARN; the
  notifications/jobs table stubs documented against the new layout.

## 11.2 Namespaces & autoload for seeders/factories

- Laravel-style top-level `Database\` namespace: `Database\Factories\…`,
  `Database\Seeders\…`, PSR-4-mapped in the HOST's composer.json
  (`"Database\\": "database/"`). The skeleton ships the mapping.
- `HasIonsFactory` resolution order becomes: (1) explicit `protected static
  $factory` (unchanged), (2) **`Database\Factories\{Model}Factory`** (new,
  Laravel parity), (3) the 4.2 convention `{ModelNamespace}\Factories\{Model}Factory`
  (fallback, BC). Document; UPGRADE-4.4 notes the new first-choice.
- `make:factory` targets `database/factories` + `Database\Factories`
  namespace when the host-root layout exists, else the legacy target
  (follows Path). `make:seeder` likewise (`database/seeders`,
  `Database\Seeders`). Generators stay GeneratorCommand-based, validated.
- Acceptance: factory in `Database\Factories` resolves via `Model::factory()`
  with zero model-side config; seeder generated into the new layout runs;
  legacy-layout generation byte-identical (regression pins).

## 11.3 `app/Models` + make:model modernization

- `make:model` (the legacy global-ns ModelCommand) modernized onto the
  GeneratorCommand base: targets `app/Models/{Name}.php`, namespace
  `App\Models`, name validation + `--force` (matching the 8.4 generators).
  Legacy flags/behaviors GROUNDED first; keep what hosts use (the stub's
  placeholders: properties/table/fillable/hidden) — modern stub with
  `HasIonsFactory` use-statement included (commented or live? LIVE — the
  trait is harmless without a factory; GROUND that claim, else commented).
- Optional `--factory` flag: also generates the matching
  `Database\Factories\{Name}Factory` (delegates to make:factory).
- Skeleton: `app/Models/.gitkeep` (or a sample model? — just .gitkeep; the
  welcome flow has no DB), docs tree updated.
- Acceptance: make:model lands in app/Models with valid code (php -l) +
  binding/factory integration test (model + --factory → factory()->create()
  works end-to-end on sqlite).

## 11.4 Skeleton, docs, release 4.4.0

- Skeleton ships the full new layout: `database/{migrations,seeders,factories,
  schemas,backups}/.gitkeep`, `Database\` PSR-4 mapping, sqlite config example
  pointing at `database/database.sqlite`, `.gitignore` entries (`database/
  *.sqlite`, `database/backups/*`).
- Docs: new docs/database-layout.md or fold into existing database docs
  (GROUND where migrate/seeders are documented today — likely console.md +
  factories.md + cache-queue-events.md; one canonical "Database layout"
  section, cross-linked). best-practices.md structure tree updated.
- UPGRADE-4.4.md: precedence note (dual-dir hosts), HasIonsFactory new
  first-choice (only affects hosts that ALREADY have a Database\Factories
  class colliding — vanishingly rare), make:model target change (generators
  only; existing models untouched).
- CHANGELOG [4.4.0]; fact-check review at the established bar; merge; push;
  tag 4.4.0 locally — user confirms push.

## Sequencing

11.1 (Path + migrate) → 11.2 (namespaces/factories/seeders) → 11.3
(make:model) → 11.4 (skeleton/docs/release). Small phase — sub-phases may
share branches where natural (11.1+11.2 touch the same surface; implementer's
call, keep commits reviewable).

## Risks

- Path::database precedence flip on dual-dir hosts → doctor WARN + UPGRADE
  note (the 9.1 playbook).
- Legacy subfolder-name mapping (Migrations vs migrations etc.) must be
  grounded exactly — wrong mapping silently runs zero migrations. Tests pin
  both layouts.
- `Database\` namespace requires a host composer.json mapping — generators
  must emit a clear hint when the namespace isn't autoloadable (message, not
  failure).
