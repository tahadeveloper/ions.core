# Logging

Channel-based logging over Monolog. Channels are defined in
`config/logging.php`, consumed through the `Ions\Support\Log` facade, and
built lazily by `Ions\Log\LogManager` (container binding `log`, registered by
`Ions\Providers\LogProvider`). Every channel masks secrets and stamps records
with a per-request correlation id.

```php
use Ions\Support\Log;

Log::info('User created', ['id' => $user->id]);   // default channel
Log::channel('audit')->warning('Role changed');   // named channel
Log::stack(['app', 'stderr'])->error('Payment failed', ['order' => $id]);
```

## Configuration (`config/logging.php`)

```php
return [
    'default' => env('LOG_CHANNEL', 'app'),

    'channels' => [
        'app'    => ['driver' => 'single', 'path' => 'app.log', 'level' => 'debug'],
        'daily'  => ['driver' => 'daily',  'path' => 'app.log', 'days' => 14],
        'stderr' => ['driver' => 'stderr', 'level' => 'warning'],
        'stack'  => ['driver' => 'stack',  'channels' => ['app', 'stderr']],
    ],
];
```

No `config/logging.php` at all? A built-in `app` channel (single file →
`var/logs/app.log`, level `debug`) keeps zero-config logging working.

### Drivers

| Driver   | Options                  | Behavior                                                                                                  |
|----------|--------------------------|-----------------------------------------------------------------------------------------------------------|
| `single` | `path`, `level`          | One file via `StreamHandler`. `path` defaults to `{channel}.log`                                            |
| `daily`  | `path`, `days`, `level`  | `RotatingFileHandler`: dated files (`app-2026-06-11.log`), pruned after `days` (default 7)                  |
| `stderr` | `level`                  | Process stderr (`php://stderr`) — the natural sink for containers and worker mode                           |
| `stack`  | `channels`               | Fan-out: one write lands on every member channel; each member keeps its own level threshold                 |

- **Paths** — relative `path` values resolve under `var/logs/`; absolute paths
  (POSIX or Windows drive-letter) are kept verbatim.
- **Levels** — `debug` / `info` / `notice` / `warning` / `error` / `critical` /
  `alert` / `emergency` (default `debug`). Records below the channel level are
  dropped by its handler.
- **Fail loud** — an unknown channel name, unknown driver, or invalid level
  throws `InvalidArgumentException` (no silent null logger).
- Channels are **memoized**: the same name always resolves the same logger
  instance (file streams stay open across writes).

## The `Log` facade

`Log::info()/error()/…` (all PSR-3 methods) proxy to the default channel
(`logging.default`, fallback `app`). `Log::channel($name)` and
`Log::stack([$a, $b])` return a `Psr\Log\LoggerInterface`, so anything that
type-hints PSR-3 can receive a channel. `Log::manager()` exposes the
underlying `LogManager`.

## Request-id correlation

Every channel carries `Ions\Bundles\RequestIdProcessor`: each record gets an
`extra.request_id` (8 random bytes, hex — 16 chars), generated lazily on the
first write and **shared by all channels within one request**, so you can grep
one request's lines across files. In worker runtimes
(`Kernel::resetForRequest()`, see [worker-mode.md](worker-mode.md)) the id is
reset between requests; in classic PHP-FPM each process/request naturally gets
its own.

## Redaction

Every channel also carries `Ions\Bundles\RedactionProcessor`: context values
under secret-bearing keys (`password`, `token`, `secret`, `authorization`,
`api_key`, … — case-insensitive substrings, recursive) are replaced with
`[REDACTED]` before anything hits a handler. See
[security.md](security.md) for the full key list.

## Legacy surface: `Logs::create()`

`Ions\Bundles\Logs::create('name.log')` predates the channel system and stays
byte-compatible: a fresh (non-memoized) `Logger('ions')` writing
`var/logs/{name}` at `Debug`, with the same redaction and request-id
processors. The framework's internal fixed-file logs (`view.log`,
`scheduler.log`, …) still use it. New application code should prefer the
`Log` facade and named channels.
