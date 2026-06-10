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
ions queue:work database --stop-when-empty --tries=3
```

The `database` connection needs the `jobs`/`failed_jobs` tables. A migration
stub ships at `src/Queue/stubs/create_jobs_table.stub` — copy it into the host's
`{src|app}/Database` directory (dropping `.stub`) and run `ions migrate`. See
[console.md](console.md) for the console runner.
