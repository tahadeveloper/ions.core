# Phase 13 — Taskflow Reference Application Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing it (the dispatch prompt carries full grounded detail). This builds a HOST APP under `examples/taskflow/` from the framework's PUBLIC APIs — it does NOT modify core (any core bug surfaced is fixed as a SEPARATE core finding-commit, not worked around). Gates: the core suite (`php83 vendor/bin/pest` from repo root) stays 1374 green zero warnings + both phpstan 0 + `php83 vendor/bin/php-cs-fixer fix --dry-run` 0 files (the example dir is NOT in cs-fixer/phpstan paths — confirm, exclude if needed); AND the example's own suite green (`php83 vendor/bin/pest` inside examples/taskflow). Merge-as-you-go. Run everything via `php83`.

**Goal:** A real project/task-tracker host app (`examples/taskflow/`) that exercises every Ions subsystem once, runs its own Pest suite in CI against the working-tree core, and serves as living documentation + integration test of the public API surface.

**Architecture:** A complete host app in the standard `app/`+`database/` layout, path-requiring the local core (`repositories: path -> ../../`, symlinked). Twig server-rendered UI + JSON API. Its own factories/fakes/TestCase suite is the CI gate.

**Tech Stack:** Ions core (working tree), Twig, Eloquent (sqlite in tests/CI), Pest + Ions\Testing\TestCase. No node.

**Spec:** `docs/superpowers/specs/2026-06-12-phase13-taskflow-reference-app-design.md`.

---

## 13.1 Scaffold + boot
Ground: study `skeleton/` (the closest template — composer.json, public/index.php, bin/ions, config set, .htaccess) + how SkeletonTest boots a host. Files: `examples/taskflow/{composer.json (path repo ->../../ symlink, App\+Database\Factories\+Database\Seeders\ PSR-4, ionzile/core @dev, pest+faker dev), public/index.php, public/.htaccess, bin/ions, config/* (full secure-default set, sqlite default for the example), .env.example, .gitignore, README.md skeleton}`. New repo-root `.gitattributes` (`examples/ export-ignore`, `tests/ export-ignore`, `docs/ export-ignore`, `bench/ export-ignore`). Confirm example dir excluded from core phpstan/cs paths. `composer install --working-dir=examples/taskflow` resolves the symlinked core. Test: `examples/taskflow/tests/SmokeTest.php` (Ions\Testing\TestCase) boots + `/up` 200 + welcome `/` 200.
Commit: `feat(example): scaffold examples/taskflow host app (path-linked core) + boot test`.

## 13.2 Domain
Ground: HasIonsFactory resolution (Database\Factories first), migrate command (database/schemas vs migrations — the 4.4 layout; ground exact subfolder migrate reads), factory/seeder namespaces. Files: `app/Models/{User,Project,Task,Attachment,Comment}.php` (User implements Authenticatable+VerifiesEmail; relationships; status enum on Task), `database/migrations/*` (users, projects, tasks, attachments, comments, project_members pivot, jobs+failed_jobs, notifications, +2FA/email_verified columns — ground where migrate discovers them), `database/factories/Database/Factories/*Factory.php`, `database/seeders/Database/Seeders/DemoSeeder.php`. Test: migrate creates tables; factories build+persist; relationships resolve; User satisfies the contracts. (Tests use sqlite :memory:, dropIfExists for MySQL-parity even though the example CI job is sqlite-only.)
Commit: `feat(example): domain — models, migrations, factories, seeders`.

## 13.3 Auth surface
Ground: EmailVerification (verificationUrl/verify/VerifiesEmail/EnsureEmailVerified 'verified' alias), TwoFactor (generateSecret/code/verify/otpauthUri/recovery), JWT/AuthController login, Gate (define/policy/authorize). Files: `app/Http/Controllers/Auth/*` (register/verify/login/2FA-enable/challenge — web Twig), `app/Http/Api/AuthController.php` (JSON login), `app/Http/Requests/*` (FormRequests), `app/Policies/{ProjectPolicy,TaskPolicy}.php`, `app/Providers/AuthServiceProvider.php` (gate define + policy map; auto-discovered), `views/auth/*`, config wiring ('verified' alias, encrypter for 2FA secret at rest). Tests: register→signed verify link→verified flips + middleware gates; 2FA enable+TOTP login; JWT API login (actingAs); policy denies non-member, allows owner.
Commit: `feat(example): auth — register/verify/2FA/login, policies`.

## 13.4 Projects & tasks CRUD
Ground: route model binding (Model+placeholder-name), FormRequest web redirect (flash/old/errors), pagination (paginate + pagination() Twig), IonUpload (store + magic-bytes), $this->view()/return view(). Files: `app/Http/Controllers/{ProjectController,TaskController}.php` (binding, authorize, view returns, pagination, attachment upload), `app/Http/Requests/{StoreProject,StoreTask,...}.php`, `app/Http/Api/{ProjectApi,TaskApi}.php` (Resource responses), `routes/web.php`+`api.php`, `views/projects/*`+`tasks/*`. Tests: create/edit via web (validation redirect-back + old/errors), binding 404 on miss + member-only, pagination links, attachment upload (legit stores, traversal/oversize rejected), API resource shape.
Commit: `feat(example): projects/tasks CRUD — binding, forms, pagination, uploads`.

## 13.5 Async, notifications, mail, scheduler
Ground: Queue/Job + dispatch, failed_jobs+queue:retry, Notification (mail+database), Mailable, App\Schedule::boot(Scheduler). Files: `app/Jobs/{SendTaskAssignedNotification,FlakyDemoJob}.php`, `app/Notifications/{TaskAssigned,CommentAdded}.php`, `app/Mail/{WelcomeMail,DigestMail}.php`, `app/Schedule.php` (daily prune + weekly digest), `app/Commands/DemoSeedCommand.php`, in-app notification list controller+view. Tests: assign→Queue::fake assertDispatched; sync end-to-end→Mail::fake/Notification::fake assertSent/assertSentTo; database channel writes notification row; flaky job → failed_jobs (sqlite); schedule:run runs due task; digest mailable built.
Commit: `feat(example): async jobs, notifications, mail, scheduler`.

## 13.6 Sharing, caching, http, encryption
Ground: signedRoute/UrlSigner + 'signed' middleware, Encrypter encrypt/decrypt, ResponseCache 'cache.response' (never caches auth), Http::fake. Files: `app/Http/Controllers/ShareController.php` (signed expiring board link + unsubscribe), public board route with `cache.response`, encrypted "note" on Project (Encrypter), `app/Services/AvatarFetcher.php` (Http client + webhook on task done). Tests: signed share link verifies + tampered/expired rejected; public board cached (2nd hit HIT, anonymous only); encrypted note round-trips; Http::fake asserts the webhook call; cached board never serves an authed view.
Commit: `feat(example): sharing (signed URLs), response cache, http client, encrypter`.

## 13.7 Coverage suite + CI job + README
Files: `examples/taskflow/tests/FeatureJourneyTest.php` (the full journey: register→verify→2FA→login→project→member→task→upload→assign→notify→share→cached board→scheduled prune, each subsystem asserted with the right fake), `.github/workflows/ci.yml` (+`example` job: PHP 8.3, sqlite, `composer install --working-dir=examples/taskflow` then pest in that dir), `examples/taskflow/README.md` (run instructions + the feature→subsystem map table + `ions serve`/`doctor` notes), `examples/taskflow/phpunit.xml` (+ pest config). Verify the path-repo symlink + autoload resolve in a clean checkout (simulate: rm -rf example vendor, composer install, pest). Tests: the journey suite green; CI job validated (push, watch).
Commit: `feat(example): full-journey coverage suite + dedicated CI job + README`.

## 13.8 Wrap
Any core API roughness surfaced during 13.1–13.7 → fixed as SEPARATE core commits (with their own core test). Link the reference app from the main README (a "Reference application" row/section). Final: core suite 1374 green + example suite green + CI (all jobs incl. `example`) green. NO version bump / NO release (the example is not a released artifact; it ships in git only). Merge phase to main, push.
Commit(s): `docs(readme): link the Taskflow reference app` + any `fix(core): <finding>`.

## Self-review
Spec coverage: every subsystem in the spec's coverage table maps to a 13.x task (auth→13.3, CRUD/binding/forms/pagination/uploads→13.4, async/notify/mail/scheduler→13.5, sharing/cache/http/encrypt→13.6, models/db→13.2, scaffold/twig/health→13.1, coverage suite/CI/testing-kit→13.7). Decisions (in-repo/path-link/CI-job/Twig) honored. Risks carried (YAGNI one-touch-per-subsystem; core findings fixed in core; CI symlink verified in clean checkout). No release step (correct — reference app, not a package). Execution-time grounding explicit per the master-plan cadence.
