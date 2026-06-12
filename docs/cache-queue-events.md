# Cache, Queue & Events

Three Illuminate-backed subsystems, each registered by a service provider and
fronted by a global helper. Config lives under `config/cache.php`,
`config/queue.php`, and `config/events.php` — see
[config.md](config.md#cache-config-configcachephp) for the full key reference.

## Cache

`Ions\Providers\CacheProvider` binds the Illuminate `CacheManager` as `cache`.
Stores resolve lazily, so only the ones you touch are built.

```php
cache();                       // the default cache repository
cache('key');                  // get a value
cache('key', 'default');       // get with a default
cache(['k' => 'v'], 60);       // put with a TTL in seconds (omit TTL → forever)
cache()->forget('key');
cache()->store('array');       // a named store as a repository
```

Config (`config/cache.php`): `default` (store used by `cache()`), `prefix`
(global key prefix), `stores` (driver definitions), and `persistent_store`.

> **`cache.persistent_store` must be a persistent driver in production.** JWT
> revocations and rate-limit counters both reuse this shared store. If it points
> at the `array` driver, revocations never persist (logged-out tokens stay
> valid until expiry) and throttle counters reset every request. Use `file`,
> `redis`, or `database` in production; `array` is for tests only. See
> [config.md](config.md#cachepersistent_store).

## Events

`Ions\Providers\EventProvider` binds the Illuminate event `Dispatcher` as
`events` and auto-registers listeners declared under `events.listen`.

```php
event(new RequestHandled($request, $response));   // dispatch an event object
event('my.event', ['payload']);                   // dispatch a named event
listen('my.event', fn ($value) => /* ... */);     // register a listener
```

```php
// config/events.php
return [
    'listen' => [
        \Ions\Events\RequestHandled::class => [
            \App\Listeners\LogRequest::class,  // resolved via the container; handle($event)
        ],
    ],
];
```

### Framework event — `RequestHandled`

`Ions\Events\RequestHandled` carries the readonly `Request` and `Response`. It is
fired once at the end of `Kernel::handle()` — for both successful and error
responses — in a fire-and-continue manner, so a failing listener never breaks the
response.

## Queue

`Ions\Providers\QueueProvider` binds the Illuminate `QueueManager` as `queue`
with the `sync` and `database` connectors (plus `redis` when the host binds a
Redis factory).

### Jobs

Extend `Ions\Queue\Job` (implements `ShouldQueue`; pulls in the Illuminate queue
traits) and implement `handle()`:

```php
final class SendWelcome extends \Ions\Queue\Job {
    public function __construct(private int $userId) {}
    public function handle(): void { /* ... */ }
}

dispatch(new SendWelcome($id));                            // default connection
dispatch((new SendWelcome($id))->onConnection('database'));
```

On `sync`, `handle()` runs inline immediately. On `database`, the job is
persisted to the `jobs` table and processed by a worker.

```php
// config/queue.php
return [
    'default' => 'sync',
    'connections' => [
        'sync'     => ['driver' => 'sync'],
        'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
    ],
];
```

### `queue:work`

```bash
ions queue:work                       # work the default connection until empty
ions queue:work database --once       # process a single job, then exit
ions queue:work database --stop-when-empty --tries=3 --backoff=10
```

The `database` connection needs the `jobs`/`failed_jobs` tables. A migration
stub ships at `src/Queue/stubs/create_jobs_table.stub` — copy it into the host's
`database/migrations/` directory (4.6+ layout; `{app|src}/Database/migrations` on the
legacy layout), dropping `.stub`, and run `ions migrate`. See
[console.md](console.md) for the console runner.

### Retries & backoff

A job is attempted up to `--tries` times (default 1); each non-final failure
releases it back onto the queue, delayed by `--backoff` seconds (default 0).
Properties on the job class always win over the CLI defaults — they are
captured into the payload at dispatch time and the Illuminate worker prefers
them over its `WorkerOptions`:

```php
final class SendWelcome extends \Ions\Queue\Job {
    public int $tries = 3;     // attempts before landing in failed_jobs
    public int $backoff = 60;  // seconds between attempts (or array [10, 60, 300])

    public function handle(): void { /* ... */ }
}
```

### Failed jobs

When a job exhausts its tries, `queue:work` records it in the failed-jobs
store: connection, queue, full payload, the exception (with trace) and
`failed_at`. Storage is configured under `config('queue.failed')` — defaults
shown:

```php
// config/queue.php
'failed' => [
    'driver'   => 'database-uuids', // 'database-uuids' | 'database' | 'null'
    'database' => null,             // connection name (null = default connection)
    'table'    => 'failed_jobs',    // created by the create_jobs_table stub
],
```

`database-uuids` (the default) keys rows by the job's payload uuid — the schema
the bundled stub creates. The plain `database` driver is for **legacy tables
without a uuid column**: with the bundled stub its insert violates the NOT NULL
uuid constraint and the failure record is silently lost — use `database-uuids`.
`null` discards failures. The `sync` driver is unaffected: a failing sync job
throws to the dispatcher inline (web requests are never recorded; inside a
`queue:work` process the listener does record it).

Inspect and recover with the failed-job commands:

```bash
ions queue:failed                # list: ID, connection, queue, class, failed at
ions queue:retry <id> [<id>…]    # push specific failed jobs back onto the queue
ions queue:retry --all           # …or everything
ions queue:forget <id>           # delete one failed job
ions queue:flush [--hours=48]    # delete all (optionally only 48h+ old)
```

The typical workflow: `queue:failed` to find the ID, fix the underlying bug,
`queue:retry <id>` (the payload is re-pushed to its original connection/queue
with its attempts reset and removed from failed_jobs), then `queue:work` picks
it up again.
