# Phase 13 — Taskflow Reference Application (Design)

Validated with the user 2026-06-12 (4 decisions confirmed: project/task-tracker
domain, in-repo `examples/`, dedicated CI job, Twig server-rendered + JSON API).

## Why

1,374 unit/feature tests prove the framework internals in isolation. They do NOT
prove a real host app — assembled by a developer from the **public** APIs, in the
standard `app/` + `database/` layout — works end-to-end. **Taskflow** is the
dogfood test: a real app touching every subsystem once, run in CI against the
local working-tree core. It is simultaneously (a) an integration test of the
public API surface, (b) living documentation, and (c) a `create-project`-grade
reference. Any awkward API / missing glue surfaced while building it is a GOLD
finding fixed in core (per the phase review discipline).

## Decisions

- **Domain:** project/task tracker.
- **Location:** in-repo `examples/taskflow/`, path-requiring the local core.
- **CI:** a dedicated job runs Taskflow's own Pest suite against the local core.
- **Frontend:** Twig server-rendered web UI + a small JSON API. No node (CI-light).

## Architecture

`examples/taskflow/` is a complete host app (the skeleton layout, fleshed out):

```
examples/taskflow/
├── composer.json        # path repo -> ../../ (ionzile/core @dev); App\ + Database\ PSR-4
├── public/index.php, .htaccess
├── bin/ions
├── config/              # full secure-default set (app/auth/cache/database/
│                        #   filesystem/logging/queue/session/events/console)
├── app/
│   ├── Models/          # User, Project, Task, Attachment, Comment
│   ├── Http/Controllers/  # web (Twig)
│   ├── Http/Api/        # JSON API
│   ├── Http/Requests/   # FormRequests
│   ├── Policies/        # ProjectPolicy, TaskPolicy
│   ├── Providers/       # AuthServiceProvider (gate define + policy map),
│   │                    #   AppServiceProvider
│   ├── Notifications/   # TaskAssigned, CommentAdded (mail + database channels)
│   ├── Mail/            # WelcomeMail, DigestMail (Mailable)
│   ├── Jobs/            # SendTaskAssignedNotification (+ one deliberately flaky
│   │                    #   job to demo retry/failed_jobs)
│   ├── Commands/        # taskflow:demo-seed (a custom console command)
│   └── Schedule.php     # daily prune (expired share links) + weekly digest
├── database/
│   ├── migrations/      # schema classes (users, projects, tasks, attachments,
│   │                    #   comments, members pivot, jobs/failed_jobs,
│   │                    #   notifications, 2FA + email_verified_at columns)
│   ├── factories/       # Database\Factories\*
│   └── seeders/         # Database\Seeders\* (demo data)
├── routes/web.php, api.php
├── views/               # layout, projects, tasks, auth, errors/404.twig, welcome
├── tests/               # the app's Pest suite (the CI gate)
└── README.md            # run instructions + feature->subsystem map
```

### Domain model

- `User` implements `Ions\Auth\Contracts\Authenticatable` + `VerifiesEmail`;
  columns: email_verified_at, two_factor_secret/recovery (nullable, encrypted at
  rest via `Ions\Security\Encrypter`). Owns projects; member of projects.
- `Project` belongs to an owner (User); has many tasks; many members (pivot);
  a nullable `share_token` for the read-only public board.
- `Task` belongs to a project; assigned_to (nullable User); status enum
  (todo/doing/done); has attachments + comments.
- `Attachment` belongs to a task; stored through `IonUpload` (magic-bytes
  validated; the 12.1 containment applies).
- `Comment` belongs to a task + author.

## Feature → subsystem coverage (every subsystem touched once)

| Subsystem | Where in Taskflow |
|---|---|
| app/Models + database/ layout (4.4) | the whole model + migrations/factories/seeders |
| JWT auth + AuthMiddleware (7.x/8.x) | `/api/auth/login`; web session login |
| Email verification (12.4) | register → signed link → `verified` middleware gates project create |
| TOTP 2FA (12.3) | optional enable + login challenge + recovery codes |
| Gate + policies (10.4) | ProjectPolicy/TaskPolicy; `$this->authorize()` in controllers |
| Route model binding (10.2) | `/projects/{project}/tasks/{task}` |
| FormRequest + form flow (7.7/10.3) | create/edit forms: validation, flash/old/errors, redirect-back |
| Pagination (10.3) | project + task lists |
| File uploads (8.3/12.1) | task attachments via IonUpload (magic-bytes) |
| Queue + failed jobs (8.5/10.5) | async TaskAssigned job + one flaky job → failed_jobs/retry |
| Notifications mail+database (8.5) | TaskAssigned/CommentAdded; in-app notification list (db channel) |
| Mailable (8.5) | WelcomeMail, DigestMail |
| Scheduler (9.4) | Schedule.php: daily prune + weekly digest |
| Signed URLs + Encrypter (8.5) | expiring share-board link; unsubscribe; encrypted note |
| Response caching (12.5) | public shared board page cached (opt-in `cache.response`) |
| HTTP client (8.5) | avatar fetch / completion webhook (Http::fake in tests) |
| Channel logging + N+1 detector (10.x) | app + audit channels; N+1 on in dev |
| Twig views + custom error pages (9.2/12.6) | server-rendered UI; errors/404.twig; welcome page |
| Console + make:* (8.4/9.x) | scaffolded with generators; taskflow:demo-seed command |
| Typed config (8.6) | config()->string/int/bool in providers |
| Health/doctor/maintenance (10.6/10.8) | /up; documented `ions doctor`/`down`/`up` |
| Testing kit + fakes + factories (8.4/8.5) | the app's own Pest suite |

## Testing & CI

- **App test suite** (`examples/taskflow/tests/`): `Ions\Testing\TestCase`
  against the example base path + factories + fakes. A feature-coverage suite
  walks the journey: register → verify (signed) → enable 2FA → login (TOTP) →
  create project → invite member → create task → upload attachment → assign
  (Queue::fake assertDispatched) → notification (Notification::fake /
  Mail::fake) → generate share link → fetch cached public board → run the
  scheduled prune. Plus focused per-subsystem tests (policy denies non-member;
  pagination links; form validation redirect-back; signed-link tamper rejected;
  flaky job lands in failed_jobs).
- **CI**: new job `example` in `.github/workflows/ci.yml` (PHP 8.3, SQLite, no
  node): `composer install --working-dir=examples/taskflow` (the path repo
  symlinks the working-tree core) then `php83 vendor/bin/pest` in that dir.
  Validates the public API surface against every future core change.
- **Packaging**: add `.gitattributes` with `examples/ export-ignore` (and
  `tests/`, `docs/`, `bench/`) so `examples/` never ships in the `ionzile/core`
  Composer archive — the reference app travels in git, not in the published
  package.

## Linking the example to the local core (the mechanism)

`examples/taskflow/composer.json`:
- `repositories: [{ "type": "path", "url": "../../", "options": {"symlink": true} }]`
- `require: { "ionzile/core": "@dev", ... }`
- `autoload.psr-4: { "App\\": "app/", "Database\\Factories\\": "database/factories/", "Database\\Seeders\\": "database/seeders/" }`
- `require-dev: pestphp/pest, fakerphp/faker`

`composer install` in the example dir symlinks `../../` as the installed core, so
the app always runs against the working tree (unreleased changes included).

## Sequencing (executed as a phase, established cadence)

13.1 scaffold (composer/path-repo/config/bin/public/.gitattributes) + boot test →
13.2 domain (models/migrations/factories/seeders) → 13.3 auth surface (register/
verify/2FA/login, policies) → 13.4 projects/tasks CRUD (binding/FormRequest/form
flow/pagination/uploads) → 13.5 async + notifications + mail + scheduler + jobs →
13.6 sharing (signed URLs/encrypter/response cache/http client) → 13.7 the
feature-coverage suite + CI job + README → 13.8 wrap (any core findings fixed,
docs link from README).

## Risks

- **Scope creep** — YAGNI hard: each subsystem touched ONCE, minimal real
  features, no production polish. The coverage table is the definition of done,
  not "a complete SaaS."
- **Core bugs surfaced** — EXPECTED and welcome. A rough API / missing glue gets
  fixed in core as a finding (separate commit), not worked around in the example.
- **CI path-repo symlink** — `composer install` in a subdir with a relative path
  repo must resolve in the GitHub runner; verify the symlink + autoload work in
  CI, not just locally.
- **No core regression** — the example is additive (new dir + one CI job + a
  `.gitattributes`); the 1,374-test core suite is unaffected.
