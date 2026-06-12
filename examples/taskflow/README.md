# Taskflow — Ions reference application

Taskflow is the in-repo reference application for the
[Ions PHP framework](https://github.com/tahadeveloper/ions.core) (`ionzile/core`).
It is a small project &amp; task tracker that exercises every Ions subsystem
once — it doubles as living documentation and an integration test of the
framework's public API surface, run in CI against the working-tree core.

Unlike a real host app (which requires a published `ionzile/core ^4.x`), this
example **path-links the local core**: its `composer.json` declares a `path`
repository to `../../` with `symlink: true` and requires `ionzile/core: @dev`.
`composer install` therefore symlinks the repo root in as the installed core,
so the suite always runs against the current working tree.

## Domain

Taskflow models a small project &amp; task tracker: users own and join projects,
projects hold tasks, and tasks carry assignees, file attachments and comments.
The Eloquent models are `User`, `Project`, `Task`, `Attachment` and `Comment`
(`app/Models/`); `User` implements the framework's `Authenticatable` and
`VerifiesEmail` contracts. Tasks move through a `todo | doing | done` status.
The schema, factories and a `DemoSeeder` live under `database/`
(`schemas/`, `factories/`, `seeders/`).

## Auth

Taskflow wires the full account journey from the framework's public auth APIs:

**register → verify → (optional 2FA) → login**, plus authorization policies.

- **Registration + email verification** — `RegisterController` creates an
  *unverified* `User` and calls `EmailVerification::sendVerification()`, which
  mails a **signed, expiring** link (`Ions\Auth\EmailVerification` + signed URLs
  + notifications). The link targets the `verification.verify` route guarded by
  the `signed` middleware; `VerifyController` runs `EmailVerification::verify()`
  to flip `email_verified_at`. The `verified` middleware then gates
  verified-only pages (e.g. `/dashboard`), redirecting unverified web users to
  `config('app.auth.email_verification_redirect')`.
- **Web session login** — the framework's default `web` middleware group carries
  no auth, so the host owns it: on a valid password `LoginController` calls
  `session()->put('auth_user_id', $id)`, and the host
  `SessionAuthMiddleware` (alias `web.auth`) reads that id, loads the `User`, and
  publishes it as the request's `auth_user` attribute — exactly what the Gate and
  the `verified` middleware read. Chain `->middleware(['web.auth', 'verified'])`
  to gate a route on a verified, logged-in session.
- **Two-factor (TOTP)** — `TwoFactorController` enrols a user with
  `Ions\Auth\TwoFactor` (secret + recovery codes + `otpauth://` URI for a QR),
  confirms with a live code, and stores the secret **encrypted at rest**
  (`Ions\Security\Encrypter`) with recovery codes hashed. When 2FA is on, login
  defers to a TOTP challenge before establishing the session.
- **JWT API login** — `app/Http/Api/AuthController` (over the framework's
  `Ions\Auth\Http\AuthController`) issues an access + refresh token pair at
  `POST /api/auth/login`; `AuthMiddleware` then resolves the `User` onto every
  other `/api/*` request. The host `App\Auth\UserProvider` (config/auth.php)
  returns the real Eloquent model so policies see relationships.
- **Gate + policies** — `AuthServiceProvider` (auto-discovered) maps
  `Project`/`Task` to `ProjectPolicy`/`TaskPolicy` and defines a `create-project`
  ability. Controllers call `$this->authorize(...)` / `app('gate')->authorize(...)`;
  views can use `can()`. Policies grant view/update to a project's owner or
  members and reserve delete for the owner.

The journey is covered end-to-end in `tests/AuthTest.php`.

## Projects &amp; tasks CRUD

`ProjectController` / `TaskController` (`app/Http/Controllers/`) and their JSON
counterparts `ProjectApiController` / `TaskApiController` (`app/Http/Api/`)
exercise the framework's CRUD surface. All web routes gate on
`['web.auth', 'verified']`; the API routes sit behind the JWT `AuthMiddleware`.

- **Route model binding** — actions type a placeholder-named `Project $project`
  / `Task $task`; the resolver fetches the record by route key during dispatch
  (404 on miss). Tasks are nested under a project
  (`/projects/{project}/tasks/{task}`, both bound), and the controller asserts
  the bound task belongs to the bound project (else a 404).
- **Authorization** — every record action calls `$this->authorize(...)` against
  the Gate, so `ProjectPolicy`/`TaskPolicy` decide access: owner-or-member may
  view/update, owner-only may delete; a non-member gets a 403.
- **Forms (FormRequest)** — `StoreProjectRequest`, `UpdateProjectRequest`,
  `StoreTaskRequest`, `UpdateTaskRequest` (`app/Http/Requests/`) declare the
  rules (title/name required, `status` in `todo|doing|done`). A failed web
  validation redirects **back** with the error bag + old input flashed
  (`errors()` / `old()` in the Twig forms, CSRF via `ionToken('web')`); the same
  request as JSON returns a **422** bag.
- **Pagination** — `index` calls `$query->paginate(5)`; the `pagination()` Twig
  function renders the page links and preserves the current query string. The
  API index returns a `ResourceCollection` with `data` + `meta` + `links`.
- **Uploads** — a task may carry an `attachment`. `TaskController` stores it
  through `IonUpload::store($file, Path::files('attachments'))`, which validates
  the extension allow-list **and** the magic bytes before any write (active
  types such as `.svg`/`.php` are denied, traversal targets refused, oversize
  files rejected). On success an `Attachment` row records the relative path; on
  rejection the user is redirected back with an error and **nothing** is written
  outside the uploads root.
- **API Resources** — `ProjectResource` / `TaskResource` (`app/Http/Resources/`)
  shape the single-`data` JSON envelope returned by the API controllers.

The CRUD surface is covered end-to-end in `tests/CrudTest.php` — including the
upload tests, which use `Ions\Filesystem\Storage::fake()` so the validated
write is intercepted onto an in-memory disk and never touches the real
`public/uploads`.

## Async: jobs, notifications, mail &amp; the scheduler

Taskflow exercises the framework's asynchronous surface — queue jobs,
notifications, mailables and the cron scheduler. All of it is covered in
`tests/AsyncTest.php`, which fakes the queue/mailer/notifier and drives a real
in-process worker against the `database` queue connection.

- **Jobs** (`app/Jobs/`) — `SendTaskAssignedNotification` extends
  `Ions\Queue\Job` (carries plain ids, re-reads the rows in `handle()`). It is
  dispatched with the `dispatch()` helper whenever a task is assigned, on both
  the web (`POST /projects/{project}/tasks/{task}/assign`) and the API
  (`POST /api/projects/{project}/tasks/{task}/assign`) paths. `FlakyDemoJob`
  declares `$tries = 2` and always throws, to demonstrate the failed-job flow.
- **Notifications** (`app/Notifications/`) — `TaskAssigned` / `CommentAdded`
  extend `Ions\Notifications\Notification` and deliver on the **mail + database**
  channels: `toMail()` returns a recipient-less mailable that the mail channel
  routes to the user's address; `toDatabase()` returns the payload the database
  channel persists as a row in `notifications`. `notify($user, …)` fans both out.
- **Mailables** (`app/Mail/`) — `WelcomeMail` (sent on register), `DigestMail`
  (queued weekly by the scheduler) and the notification bodies, all extending
  `Ions\Mail\Mailable` with a Twig `view()` body (`views/emails/*.twig`). Send
  inline with `->send()` or defer with `->queue('database')`.
- **In-app notifications** — `NotificationController` (`GET /notifications`)
  lists the logged-in user's database-channel rows (newest first), with a
  mark-read action.
- **Scheduler** (`app/Schedule.php`) — `App\Schedule::boot(Scheduler $schedule)`
  is discovered by convention and registers a daily `prune-read-notifications`
  task (`->withoutOverlapping()`) plus a `weekly-digest` task that queues a
  `DigestMail` per active user. Both runners drive it:

  ```bash
  php bin/ions schedule:run        # console runner (wire to crontab, every minute)
  ```

- **Background processing** — switch `QUEUE_CONNECTION=database` (config in
  `config/queue.php`), then:

  ```bash
  php bin/ions queue:work database     # process jobs
  php bin/ions queue:failed            # list failed jobs (FlakyDemoJob lands here)
  php bin/ions queue:retry --all       # re-push them
  php bin/ions queue:forget <uuid>     # drop one
  php bin/ions queue:flush             # drop all
  ```

- **Demo data** — `php bin/ions taskflow:demo-seed` (`app/Commands/DemoSeedCommand`,
  auto-discovered from `app/Commands`) migrates the schema and runs the
  `DemoSeeder` so you can explore the app with `ions serve`. Demo users
  `alice@example.com` / `bob@example.com` sign in with the password `password`.

## Quick start

```bash
cd examples/taskflow

# Install dependencies — this symlinks ../../ (the local core) into vendor/.
composer install

cp .env.example .env
# Set APP_KEY in .env (64-char hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# Sanity-check the setup (APP_KEY, writable var/, DB, security posture):
php bin/ions doctor

# Create the SQLite database (default DB_CONNECTION=sqlite):
php bin/ions migrate

php bin/ions serve     # dev server on http://127.0.0.1:8000 (--host/--port)
```

(`serve` wraps PHP's built-in server — `php -S localhost:8000 -t public`
works just as well.)

Open <http://localhost:8000> for the welcome page;
`curl localhost:8000/api/ping` returns `{"status":"success","data":{"message":"pong"}}`;
`curl localhost:8000/up` is the health probe.

> Local HTTP dev: session cookies are `Secure` by default (4.1). If you need
> session/CSRF over plain `http://localhost`, set `'cookie_secure' => 'auto'`
> in `config/session.php`.

## Running the tests

The example ships its own Pest suite, run from inside this directory against
the symlinked core:

```bash
cd examples/taskflow
composer install
vendor/bin/pest
```

Tests extend `Ions\Testing\TestCase` (via `Tests\TaskflowTestCase`, whose
`basePath()` points at this directory) and boot a fresh kernel in-process per
test. No real `.env` is committed — the bootstrap materialises one from
`.env.example` for the duration of the run and forces a SQLite `:memory:`-style
throwaway environment (`SESSION_DRIVER=array`, `APP_DEBUG=true`, a dummy
`APP_KEY`).

## Where things live

| Path | Purpose |
| --- | --- |
| `public/index.php` | Front controller (`Kernel::boot()` + `run()`). |
| `bin/ions` | Console entry point (`migrate`, `serve`, `doctor`, …). |
| `config/` | Per-file config arrays (secure defaults; SQLite by default). |
| `app/Models/` | Eloquent models (`User`, `Project`, `Task`, `Attachment`, `Comment`). |
| `app/Http/Controllers/` | Web controllers (`HomeController`, `Auth/*`, `ProjectController`, `TaskController`, `NotificationController`). |
| `app/Http/Api/` | JSON API controllers (`AuthController`, `ProjectApiController`, `TaskApiController`). |
| `app/Jobs/` | Queue jobs (`SendTaskAssignedNotification`, `FlakyDemoJob`). |
| `app/Notifications/` | Notifications (`TaskAssigned`, `CommentAdded`; mail + database channels). |
| `app/Mail/` | Mailables (`WelcomeMail`, `DigestMail`, notification bodies). |
| `app/Commands/` | Console commands (`DemoSeedCommand` → `taskflow:demo-seed`). |
| `app/Schedule.php` | Cron schedule (`App\Schedule::boot(Scheduler)`). |
| `app/Http/Middleware/` | Host middleware (`SessionAuthMiddleware`, alias `web.auth`). |
| `app/Http/Requests/` | FormRequests (`RegisterRequest`, `LoginRequest`, `Store/Update{Project,Task}Request`). |
| `app/Http/Resources/` | API resources (`ProjectResource`, `TaskResource`). |
| `app/Auth/` | `UserProvider` resolving the `User` model for JWT/Gate. |
| `app/Policies/` | `ProjectPolicy`, `TaskPolicy`. |
| `app/Providers/` | `AuthServiceProvider` (gate policy map + abilities; auto-discovered). |
| `routes/web.php`, `routes/api.php` | Route definitions (welcome, auth, projects/tasks CRUD, API). |
| `views/` | Twig templates (`layout.twig`, `auth/*`, `projects/*`, `tasks/*`, `notifications/*`, `emails/*`). |
| `database/schemas/` | Migrations (`ions migrate` discovers `database/schemas/*.php`). |
| `database/factories/` | Model factories (`Database\Factories\…`). |
| `database/seeders/` | Seeders (`Database\Seeders\DemoSeeder`). |
| `tests/` | Pest suite (`SmokeTest` boot gate, `AuthTest`, `CrudTest`, `DatabaseTest`). |

> The full feature → subsystem coverage map (auth, CRUD, uploads, jobs, mail,
> scheduler, signed links, response cache, encryption) lands with the
> coverage suite in a later sub-phase (13.7).
