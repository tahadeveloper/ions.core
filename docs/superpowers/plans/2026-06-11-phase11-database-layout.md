# Phase 11 — Standard Database Layout Implementation Plan (→ 4.4.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing it. Gates: `php83 vendor/bin/pest` green zero warnings (1166+ baseline), PHPStan level-5 main + level-8 core both 0, strict_types for new PSR-4, TDD + two-stage review + merge-as-you-go. Run everything via `php83`.

**Goal:** Laravel-standard host layout for data: host-root `database/` (migrations/seeders/factories/schemas/backups/sqlite) with full legacy fallback, `Database\` namespaces, and a modernized `make:model` targeting `app/Models` — released as 4.4.0.

**Architecture:** One precedence flip in `Path::database()` (the 9.1 playbook) carries the commands; namespaces ride the host composer.json mapping; make:model moves onto the hardened GeneratorCommand base.

**Spec:** `docs/superpowers/specs/2026-06-11-phase11-database-layout-design.md`.

---

## 11.1 Path + migrate/seeder/schema/dump commands
Ground FIRST: the exact legacy `{app|src}/Database` subfolder names each command consumes (read MigrateCommand/RollbackCommand/SeederCommand/SchemaCommand/DumpCommand + Path::database). Files: `src/Bundles/Path.php` (database() precedence + per-sub mapping), the commands if any hardcode subfolders, `src/Foundation/Doctor.php` (+dual_database_dirs WARN), fixtures (a database/-layout fixture variant or temp hosts). Tests: new-layout migrate runs; legacy fixture untouched; precedence when both; doctor.
Commit: `feat(path): host-root database/ layout — precedence over legacy {app|src}/Database, doctor check`.

## 11.2 Database\ namespaces — factories + seeders
Files: `src/Database/HasIonsFactory.php` (resolution order: $factory → Database\Factories\{Model}Factory → {ModelNs}\Factories\{Model}Factory), `src/commands/MakeFactoryCommand.php` + `SeederCommand`/`make:seeder` equivalent (ground what exists) targeting the new layout when present (+ namespace hint when Database\ unmapped), stubs. Tests: Database\Factories resolution zero-config; legacy fallback pins; generation into both layouts; autoload-hint message.
Commit: `feat(database): Database\Factories/Seeders namespaces — HasIonsFactory Laravel-parity resolution`.

## 11.3 make:model → app/Models
Ground the legacy ModelCommand surface (flags, stub placeholders, current target). Files: modernized command on GeneratorCommand (validation/--force), `app/Models` + `App\Models` target, modern stub (+HasIonsFactory — live if harmless, grounded), `--factory` flag delegating to make:factory, skeleton `app/Models/.gitkeep`. Tests: generation + lint + end-to-end model+factory on sqlite; legacy regression decision documented (replace vs coexist — REPLACE the command registration, keep BC flags that matter).
Commit: `feat(console): make:model — app/Models target, GeneratorCommand base, --factory`.

## 11.4 Skeleton + docs + release 4.4.0
Skeleton full layout + PSR-4 + sqlite example + .gitignore; canonical "Database layout" docs section (ground current doc locations); best-practices tree; UPGRADE-4.4.md; CHANGELOG [4.4.0]; fact-check review; merge; push; tag 4.4.0 locally (user confirms push).
Commit: `docs(release): 4.4.0 — database layout, changelog, UPGRADE-4.4`.

## Self-review
Spec coverage 11.1–11.4 ✓; grounding-first explicit where legacy surfaces are unknown (subfolder names, ModelCommand flags, seeder command shape); risks carried from spec (silent zero-migrations mapping, dual-dir, namespace autoload hint).
