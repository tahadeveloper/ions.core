# Best practices

The opinionated guide to structuring an Ions application. Everything below is
a recommendation, not a requirement — the framework runs fine without any of
it — but these are the conventions the framework's own defaults, generators
and skeleton are built around, so following them means fighting nothing.

Each section links to the reference doc that covers the machinery in depth.

## Structure: the `app/` layout

Since 4.2, `app/` is the conventional home for application code (`Path`
checks it before the legacy `src/` — see [UPGRADE-4.2.md](../UPGRADE-4.2.md)).
Start from the [skeleton](skeleton.md) and keep the layout:

```
my-app/
├── public/
│   └── index.php              # Front controller: Kernel::boot() + Kernel::run()
├── bin/ions                   # Console entry
├── config/                    # One PHP file per namespace (app.php, auth.php, …)
├── routes/
│   ├── web.php                # HTML routes
│   └── api.php                # /api/* routes (JSON errors, AuthMiddleware)
├── app/                       # App\ (PSR-4: "App\\": "app/")
│   ├── Http/
│   │   ├── Controllers/       # Web controllers
│   │   ├── Api/               # API controllers
│   │   └── Requests/          # FormRequest classes (make:request)
│   ├── Models/                # Eloquent models — App\Models (make:model)
│   ├── Providers/             # Auto-discovered service providers
│   ├── Services/              # Your domain services (any name works — it's your code)
│   ├── Jobs/                  # Queued jobs (make:job)
│   ├── Events/ + Listeners/   # Events (make:event / make:listener)
│   ├── Notifications/         # Notifications
│   ├── Commands/              # Console commands, auto-discovered
│   └── Schedule.php           # Scheduled tasks (docs/scheduler.md)
├── database/                  # Host-root layout (4.4) — wins over app/Database
│   ├── migrations/
│   ├── seeders/               # Database\Seeders (make:seeder)
│   ├── factories/             # Database\Factories (make:factory)
│   └── schemas/               # Schema dumps (dump/schema commands)
├── views/                     # Twig templates
├── tests/                     # Pest/PHPUnit on Ions\Testing\TestCase
├── var/                       # Writable: cache/, logs/, templates/
└── .env
```

The `database/` tree is the Laravel-standard host-root layout (since 4.4): `make:migration`/`make:seeder`/`make:factory`
and schema dumps target it, and it takes precedence over the legacy `{app|src}/Database`. Register the factory and seeder
namespaces in your composer.json — `"Database\\Factories\\": "database/factories/"` and
`"Database\\Seeders\\": "database/seeders/"` — then `composer dump-autoload`. `make:model` generates into `app/Models`
(`App\Models`) using `HasIonsFactory`, which resolves `Database\Factories\{Model}Factory` automatically.

Only `Http/`, `Providers/`, `Commands/`, `Factories/` and `Schedule.php` carry
framework conventions (dispatch, discovery, factory resolution, the
scheduler); the rest is the layout the `make:*` generators emit into and is
yours to reshape. Don't keep a `src/` directory next to `app/` — `src/` would
be silently ignored, and `ions doctor` warns about the dual layout.

## Thin controllers

A controller should translate HTTP into a domain call and a domain result
into a response — nothing else. Three habits get you there:

**1. Validate with a [FormRequest](resources.md#form-requests)** instead of
inline `validate()` calls — the rules become a named, reusable, testable
class, and a failure handles itself without any try/catch: API/JSON requests
get a 422 error bag, web form POSTs redirect back with the errors and input
flashed for `errors()`/`old()` (the [form flow](forms.md), 4.3):

```php
$data = StoreUserRequest::validate($request);   // array, or a thrown failure (422 / redirect back)
```

**2. Inject services through the constructor** — controllers are
[container-built](controllers.md#dependency-injection) (4.2), so dependencies
are declared, typed and swappable in tests. Don't reach for `app('...')`
inside actions; if an action needs a service the constructor doesn't have,
type-hint it on the action and [method injection](controllers.md#action-and-boot-argument-resolution)
provides it.

**3. Return values, don't write output** — return a
[`view()`](views.md#returning-views-from-actions-42) from web actions and an
[API `Resource`](resources.md#api-resources) (or `Json::ok()`) from API
actions; after a state-changing POST, return a
[fluent redirect](forms.md#the-fluent-redirect-api)
(`redirect()->route('users.show', ['id' => $user->id])->with('status', 'Saved.')`)
rather than rendering in place. Never `echo`; avoid writing to the shared
kernel response.

Two more 4.3 ergonomics keep list endpoints thin: let an
[Eloquent-typed, placeholder-named parameter](controllers.md#route-model-binding)
fetch the record (bound-or-404 — no `find()`/`abort()` boilerplate), and
return `$query->paginate(15)` to the view, rendering the links with the Twig
[`pagination()`](views.md#pagination-43) function instead of hand-rolling
page math.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Services\UserService;
use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

class UsersController extends BaseController
{
    public function __construct(private readonly UserService $users)
    {
        parent::__construct();   // required when subclassing BaseController
    }

    public function index(Request $request): View
    {
        // Controller-relative: UsersController -> views/users/index.twig
        return $this->view('index', ['users' => $this->users->all()]);
    }

    public function store(Request $request): View
    {
        $user = $this->users->create(StoreUserRequest::validate($request));

        return view('users.show', ['user' => $user]);
    }
}
```

Cross-cutting per-controller concerns belong in the
[lifecycle hooks](controllers.md#new-hooks) — `middleware()` for guards that
exist as middleware, `beforeAction()` for ad-hoc authorization
short-circuits, `afterAction()` for response decoration. Resist putting
business logic in hooks; they are HTTP plumbing.

## Authorization

Keep "who may do what" out of controllers and templates: define abilities and
[policies](auth.md#authorization-gate--policies) once, in an auto-discovered
`app/Providers/AuthServiceProvider`, and check them everywhere through the one
gate — `$this->authorize('update', $post)` in actions (403 on deny),
`can('update', $post)` in services/helpers, `{% if can('update', post) %}` in
Twig. Prefer policies over loose abilities as soon as a check concerns a model
class — the policy collects every rule for that model in one named, testable
place. Write ability/policy signatures with a **non-nullable** `$user`
parameter unless guests are genuinely allowed: the gate auto-denies guests for
non-nullable signatures, so "members only" stays the safe default. Remember the
gate only sees a user where `AuthMiddleware` ran with a configured
`UserProvider` — on web routes without an auth pipeline every check is a guest
check unless you scope it with `forUser($user)`.

## Wiring: providers + the container

Bind your services in a provider under `app/Providers/` — providers there are
[auto-discovered](packages.md#provider-auto-discovery-extraionsproviders) (no
`app.providers` entry, no `Booting.php` glue) and run **after** framework and
package providers, so host bindings always win:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserService;
use Ions\Container\ServiceProvider;

class AppProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind interfaces to implementations; bind ONLY — no side effects here.
        $this->container->singleton(UserService::class);
    }

    public function boot(): void
    {
        // Side-effecting startup; may resolve anything registered above.
    }
}
```

Generate one with `make:service-provider`. Two rules keep boot predictable:
`register()` only binds (every provider's `register()` runs before any
`boot()`), and anything resolvable purely from type-hints needs **no**
binding at all — the container auto-wires concrete classes, so only bind
interfaces, configuration-dependent singletons, and things with non-trivial
construction. After `composer require`/`update` in production, re-run
`ions optimize` so the discovery cache picks up new providers
([performance.md](performance.md)).

## Configuration: typed accessors, `.env` only at the edge

Read config through the [typed accessors](config.md#typed-accessors) in any
code where a wrong type should be a loud failure:

```php
$ttl     = config()->int('app.jwt.ttl');          // InvalidArgumentException on mismatch
$origins = config()->array('app.cors.origins');
$name    = config()->string('app.name');
```

`config('key', $default)` is fine for genuinely optional values; the typed
form is better for values your code depends on, because `'3600'` (a string
that slipped in from `.env`) fails fast instead of misbehaving later.

Keep `env()`/`$_ENV` reads **inside `config/` files only**. Code reads
`config()`, never the environment directly — that is what makes
`config:cache` safe (cached config never re-reads `.env`) and tests able to
override values per run.

## Events, jobs, notifications — which one when

All three decouple "something happened" from "what to do about it"; they are
not interchangeable ([cache-queue-events.md](cache-queue-events.md),
[notifications.md](notifications.md)):

- **Event** (`event(new OrderPlaced($order))`) — *something happened, and the
  current request doesn't care who reacts.* Listeners run synchronously,
  in-process. Use it to keep side effects (audit log, cache invalidation,
  counters) out of the service that did the work. Wire listeners in
  `config/events.php` or `listen()`.
- **Job** (`dispatch(new GenerateReport($id))`) — *work that should not block
  (or outlive) the request.* Anything slow, retryable, or batch-shaped:
  imports, exports, external API syncs. Jobs extend `Ions\Queue\Job`; on the
  `sync` connection they run inline (dev), on `database`/`redis` a
  `queue:work` worker processes them.
- **Notification** (`notify($user, new OrderShipped($order))`) — *a person
  must be told.* One class describes the message once, `via()` picks the
  channels (mail, database, custom), and the recipient routing lives with the
  notifiable, not the caller. Prefer it over hand-rolled `Mailable` sends
  whenever the recipient is a user of the system.

They compose: a listener for `OrderPlaced` may `dispatch()` a job, and the
job may `notify()` the user when it finishes. Start synchronous and simple;
introduce a job only when a request is measurably waiting on work it doesn't
need to wait for. Once real work runs on a queue, give jobs explicit
`$tries`/`$backoff` and treat the
[failed-jobs store](cache-queue-events.md#failed-jobs) as part of operations:
`queue:failed` in your runbook, `queue:retry` after the fix.

## Logging: channels, not files

Log through the [`Log` facade](logging.md) and shape destinations in
`config/logging.php` instead of scattering `Logs::create('some.log')` calls:

```php
Log::info('Order shipped', ['order' => $order->id]);   // default channel
Log::channel('audit')->notice('Role granted', [...]);  // dedicated stream
```

Habits that keep logs useful:

- **One default `stack`** — fan the default channel out to a `daily` file
  (rotation built in, no logrotate config) plus `stderr` in containerized
  deploys, so the platform's log collector sees everything.
- **A separate channel per audience** (`audit`, `payments`) beats grepping
  one giant file; `Log::channel()` is one word at the call site.
- **Pass context arrays, not interpolated strings** — secret-bearing keys
  (`password`, `token`, `api_key`, …) are auto-redacted, and every entry
  carries a per-request `extra.request_id`, so one request's lines correlate
  across channels.

## Testing

Subclass [`Ions\Testing\TestCase`](testing.md) and test through HTTP — real
kernel, real routing, real middleware, no web server. Point the test `.env`
at in-memory drivers (SQLite `:memory:`, `SESSION_DRIVER=array`, array
cache/queue) and set `APP_DEBUG=true` so boot errors surface as failures, not
process exits. Build data with [factories](factories.md), isolate the
outside world with [fakes](testing.md#fakes-queue-event-storage-mail-notifications-http):

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Factories\UserFactory;
use App\Jobs\SyncCrm;
use Ions\Support\Queue;

final class RegistrationTest extends AppTestCase   // your TestCase subclass
{
    public function test_registration_creates_a_user_and_queues_the_crm_sync(): void
    {
        Queue::fake();

        $this->json('POST', '/api/users', ['name' => 'Ion', 'email' => 'ion@example.test'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ion');

        Queue::assertDispatched(SyncCrm::class);

        // Validation contract: missing email -> 422 with an errors bag
        $this->json('POST', '/api/users', ['name' => 'No Email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function test_a_user_reads_their_own_profile(): void
    {
        $user = UserFactory::new()->create();

        $this->actingAs($user->id)            // real JWT through the configured signer
            ->get('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
```

Habits that pay off:

- **Assert the HTTP contract** (status, JSON shape via
  `assertJson`/`assertJsonPath`) rather than implementation details — those
  tests survive refactors.
- `actingAs($userOrId)` for protected `/api` routes — it issues a **real**
  JWT, so `AuthMiddleware` is exercised, not bypassed (requires `APP_KEY` in
  the test `.env`).
- Unit-test domain services directly (they're constructor-injected plain
  classes — no framework needed); keep the HTTP tests for wiring and
  contracts.
- Host schedules are a [pure registry you can assert against](scheduler.md#testing-host-schedules)
  — no fake needed.

## Security checklist

The 4.1+ defaults are secure-by-omission; your job is mostly to not undo
them. Before going live:

- [ ] **`APP_KEY` set** — random, ≥ 32 bytes (64-char hex:
  `php -r "echo bin2hex(random_bytes(32));"`). JWT signing, the `Encrypter`
  and signed URLs all derive from it. Never commit it; rotate it knowing all
  tokens/links die.
- [ ] **`APP_DEBUG=false`** in production — debug mode also disables the
  route/config/discovery caches and renders the rich (source-revealing) error
  page.
- [ ] **`app.trusted_hosts` configured** ([config.md](config.md#apptrusted_hosts))
  — an empty list disables host validation entirely; fine locally, not in
  production.
- [ ] **`app.trusted_proxies` configured when behind a TLS-terminating
  proxy/LB** ([config.md](config.md#apptrusted_proxies)) — without it
  `isSecure()` is `false` there, so HSTS is never emitted and
  `cookie_secure => 'auto'` resolves insecure; with it the proxy's
  `X-Forwarded-*` headers are honoured. Never list proxies you don't control.
- [ ] **Email action links are [signed URLs](security.md#signed-urls)** —
  password resets, verification, unsubscribe: `signedRoute('name', [...],
  new DateTimeImmutable('+48 hours'))` + the `signed` middleware. Tamper-proof
  and expiring, no token table.
- [ ] **Uploads: keep the built-in validation on** — the extension
  allow-list, the hard-coded executable deny-list, and magic-bytes content
  checks run by default through `IonUpload`/`IonDisk`
  ([config.md](config.md#appuploadsallowed)). Tighten `app.uploads.allowed`
  to only what the app actually accepts; never bypass the validator with
  raw `move_uploaded_file()`.
- [ ] **CORS stays deny-by-default** — list explicit origins in
  `app.cors.origins` when needed; never `['*']` with credentials.
- [ ] **`cache.persistent_store` is a persistent driver** (`file`/`redis`/
  `database`) — JWT revocation and rate-limit counters live there; `array`
  silently disables both ([config.md](config.md#cachepersistent_store)).
- [ ] **Web-cron**: if you expose `/cron/schedule`, front it with a
  secret-checking middleware ([scheduler.md](scheduler.md#web-cron--get-cronschedule));
  prefer `schedule:run` from system cron.
- [ ] **Run `ions doctor`** — it checks all of the above (key length, debug
  mode, trusted hosts, CORS wildcard, session cookie overrides, writable
  `var/`, caches, DB connectivity) and exits non-zero on critical failures;
  wire `ions doctor --json` into CI/deploy.

## Performance checklist

- [ ] **`ions optimize` on every deploy** — compiles the route, config and
  provider-discovery caches (and `optimize:clear` before a rollback). All
  three are bypassed while `APP_DEBUG` is on, so there is no dev-mode
  staleness to manage. See [performance.md](performance.md).
- [ ] **In development, turn on `database.query_log`** — it feeds
  `debugQuery()` and the [N+1 detector](performance.md#n1-query-detector-debug-only),
  which logs repeated query patterns to `var/logs/performance.log`. It is
  debug-only by design; leave it **off** in production (it buffers every
  statement in memory).
- [ ] **Keep [ORM strict mode](config.md#databasestrict) on in debug** (the
  4.3 default) — lazy loads throw with the offending relation named, so N+1s
  die in development instead of shipping. Fix the `with()` call rather than
  reaching for the `database.strict => false` escape hatch.
- [ ] **Cache-bust assets the built-in way** — `vite()` output is
  content-hashed, `asset()` appends `?v=filemtime` ([assets.md](assets.md));
  build frontend assets in CI, not on the server.
- [ ] **opcache + preload** — `preload:generate` writes an `opcache.preload`
  file covering the framework hot path ([performance.md](performance.md#opcache-preload-optional)).
- [ ] **Measure before optimizing** — the framework's hot path is already
  sub-millisecond on the fixture; your queries and external calls dominate.
  When PHP-FPM itself becomes the bottleneck, see the experimental
  [worker mode](worker-mode.md).

## Scheduling & deployment

Define recurring work fluently in `App\Schedule::boot(Scheduler $schedule)`
and drive everything from **one** crontab line (`schedule:run`) — guard
long-running tasks with `withoutOverlapping()`. Full reference:
[scheduler.md](scheduler.md).

For the server itself — nginx/Apache configs that only expose `public/`,
PHP-FPM pool sizing, the TLS-proxy guidance (`app.trusted_proxies`), and the deploy checklist ending in
`ions optimize && ions doctor` — see [deploy.md](deploy.md).

Three ops facilities to wire in from day one (all 4.3):

- **Point the load balancer / uptime monitor at [`/up`](console.md#the-up-health-endpoint)**
  — a plain 200 liveness probe; add `?checks=1` + `app.health.token` where a
  monitor should see the full `doctor` JSON.
- **Deploy risky migrations behind [maintenance mode](deploy.md#maintenance-mode)**
  — `ions down --retry=120 --secret=…`, deploy, verify through the bypass
  cookie, `ions up`. The 503 is themeable (`views/errors/503.twig`) and `/up`
  keeps answering for the monitors.
- **Locally, `ions serve`** — PHP's built-in dev server on `public/`; never
  in production.
