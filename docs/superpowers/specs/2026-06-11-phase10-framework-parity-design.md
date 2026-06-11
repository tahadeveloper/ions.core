# Phase 10 — Framework Parity: faster · secured · smart · easy (Design → 4.3.0)

Validated with the user 2026-06-11 (the Symfony/Laravel/Slim gap-analysis
shortlist, approved as presented). All items additive; release framing
**4.3.0**. Execution: the Phase 8/9 cadence (TDD, two-stage subagent review,
merge-as-you-go; gates `php83 vendor/bin/pest` green zero warnings, PHPStan
level-5 main + level-8 core both 0, new PSR-4 code strict_types).

Deferred by design to a future perf-audit phase (4.4 candidates): HTTP
response caching (Symfony HttpCache-style) and worker-mode promotion from
experimental. Security backlog staying on the backlog: 2FA/TOTP,
email-verification flow, refresh-token family tracking.

## 10.1 Trusted proxies (from Symfony) — security debt

- `config('app.trusted_proxies')`: list of IPs/CIDRs, or `'*'` (trust the
  connecting peer — common single-LB case). Applied at boot via
  `Request::setTrustedProxies($proxies, $headerSet)`; header set configurable
  `app.trusted_proxy_headers` (default `HEADER_X_FORWARDED_FOR | HOST | PORT
  | PROTO` — sensible LB default; document AWS ELB alternative).
- Worker/test hygiene: cleared in `resetForTesting()` (the trusted-hosts
  precedent from 8.4).
- Doctor check `trusted_proxies` (INFO when unset, OK when set); docs: remove
  /soften the three "no trusted-proxy support" caveats (UPGRADE-4.1 §auto,
  docs/config.md, docs/deploy.md) — `'auto'` cookie_secure and HSTS now work
  behind a configured proxy.
- Acceptance: behind a simulated proxy (X-Forwarded-Proto https +
  REMOTE_ADDR = trusted), `Request::isSecure()` true, client IP from XFF;
  untrusted peer ignored; HSTS emitted; `'auto'` resolves secure.

## 10.2 Route model binding (from Laravel) — smart

- Extends 9.3's `ActionArgumentResolver`: a parameter whose TYPE is an
  Eloquent `Model` subclass AND whose NAME matches a route placeholder →
  resolve `Model::where(routeKeyName, value)->first()`; null → 404
  (`abort(404)` semantics through the standard handler). `getRouteKeyName()`
  honored (Eloquent's own, default PK).
- Nullable model param + miss → null injected instead of 404.
- No custom-key route syntax (`{user:slug}`) in this phase — `routeKeyName`
  covers the convention case; document the scope cut.
- Works for controller actions and closures (same resolver). DB engine not
  booted → clear 500 explaining the binding needs the `db` engine.
- Acceptance: bound model injected end-to-end through `Kernel::handle()`
  (fixture model + sqlite); miss → 404; nullable miss → null; routeKeyName
  override; scalar params and services keep 9.3 behavior (regression pins).

## 10.3 Pagination + the web form flow (from Laravel) — easy

- **Pagination:** enable Illuminate's paginator (`$query->paginate($n)`)
  by wiring its page/path resolvers to the Ions request at boot (lazy);
  `Ions\View\PaginationExtension` Twig function `pagination(paginator)`
  rendering prev/next + window links (one clean default template, overridable
  via a host view), query-string preservation. JSON: paginator already
  serializes — document with API resources.
- **Form flow:** session-backed flash: `flash($key, $value)` / consumed-once
  semantics; `back()` helper (Referer-based redirect Response);
  `->withErrors($bag)->withInput()` on the redirect (store in flash);
  `old('field', $default)` helper; Twig globals/functions `errors` (bag with
  `has/first/all`) + `old()`. FormRequest web mode: on validation failure for
  a non-JSON request, redirect back with errors+input instead of 422 JSON
  (content-negotiated; API behavior unchanged).
- Acceptance: paginate over fixture rows renders correct links + preserves
  query; flash survives exactly one request (array driver semantics across
  two `handle()` calls); failed FormRequest on a web POST → 302 back +
  errors/old in the next render; API POST keeps 422 JSON.

## 10.4 Gate & policies (from Laravel) — secured

- `Ions\Auth\Gate` (lazy 'gate' binding): `define('ability', fn($user, ...$args) => bool)`,
  `policy(Model::class, PolicyClass::class)`, `allows/denies/authorize`
  (authorize → 403 abort). Policy convention: methods named per ability
  receiving `($user, $model)`. User = the request's authenticated user
  (`auth_user`/`auth_user_id` attributes from AuthMiddleware; Gate resolves
  lazily per request).
- `can('update', $post)` helper + Twig `can()` function + controller
  ergonomics: `$this->authorize('update', $post)` in BaseController/Api.
- Host wiring: policies/abilities defined in a provider (auto-discovered
  `app/Providers/AuthServiceProvider` convention documented, not required).
- Acceptance: ability + policy paths allow/deny end-to-end; authorize → 403;
  Twig `can()`; guest (no user) denies by default; works without Sentinel
  (any Authenticatable).

## 10.5 Queue resilience (from Laravel/Symfony Messenger) — faster (throughput)

- Failed jobs: `failed_jobs` table stub (jobs-table precedent), database
  driver records payload/exception/failed_at on final failure.
- Retries/backoff: `$tries`/`$backoff` properties on `Ions\Queue\Job` honored
  by `queue:work` (Illuminate worker options — ground what the existing
  worker supports and surface it; if the Illuminate worker already handles
  these, this task is config+docs+stub+commands).
- Commands: `queue:failed` (list), `queue:retry {id|--all}`, `queue:forget
  {id}`, `queue:flush`.
- Acceptance: a job failing N times lands in failed_jobs once; retry re-runs
  it; backoff honored (frozen-clock or sleep-spy); sync driver unaffected.

## 10.6 The smart trio

- **`/up` endpoint:** built-in route (like `/cron/schedule`): 200 "ok"
  liveness, no boot side effects; `?checks=1` + `app.health.token` query
  token → doctor JSON (token required; 403 otherwise). Config off-switch
  `app.health.enabled => false`.
- **Debug toolbar lite:** debug-only middleware injecting a footer bar into
  HTML responses (request ms, route name, query count + total ms from the
  query log when enabled, peak memory, PHP/Ions versions). Zero cost when
  APP_DEBUG off (not attached). Never breaks a response (inject only on
  text/html with </body>; try/catch).
- **ORM strict mode:** `Model::preventLazyLoading()` + `preventSilentlyDiscardingAttributes()`
  enabled when APP_DEBUG && `database.strict` (default true in debug? —
  decide at execution: default FOLLOW debug, escape hatch
  `database.strict => false`); violations throw in dev with the offending
  relation named. Complements (not replaces) the 8.6 log heuristic.
- Acceptance: /up 200 + token-gated checks; toolbar appears in debug HTML,
  absent in prod and in JSON; lazy-load in debug throws; off-switches work.

## 10.7 Cheap-wins batch (from Slim/Laravel) — easy

- **PSR-15 adapter:** `Ions\Http\Middleware\Psr15Adapter` wrapping any PSR-15
  middleware as an Ions `MiddlewareInterface` via symfony/psr-http-message-bridge
  + nyholm/psr7 (new deps — confirm weights; both tiny). Usable in
  middleware_aliases: `'cors' => [Psr15Adapter::class, VendorMw::class]`?? —
  simplest: hosts subclass or factory-bind; design at execution, acceptance
  is "a real PSR-15 middleware runs in the Ions pipeline with request/response
  changes propagating both ways".
- **Maintenance mode:** `ions down [--secret=] [--retry=]` / `ions up`; flag
  file in var/; early check in `handle()` → 503 + Retry-After (template
  overridable), secret cookie bypass. Doctor row.
- **`ions serve`:** wraps `php -S` with host/port options pointing at public/.
- Acceptance: each usable in one command/3 lines, tested, documented.

## 10.8 Release 4.3.0

- CHANGELOG `[4.3.0]`, UPGRADE-4.3 (only real behavior notes: ORM strict in
  debug, FormRequest web redirect behavior — both flagged), docs pass,
  best-practices.md updates (authorization section, pagination, health),
  fact-check review at the 8.7/9.7 bar, merge, push, tag (user confirms tag
  push).

## Sequencing

10.1 (small, unblocks docs) → 10.2 (resolver extension) → 10.3 (forms+pagination,
biggest UX) → 10.4 (gate) → 10.5 (queue) → 10.6 (smart trio) → 10.7 (cheap
wins) → 10.8 release.

## Risks

- FormRequest web-redirect changes existing non-JSON failure behavior (422
  HTML today?) — ground exact current behavior; UPGRADE note; content
  negotiation must keep API contracts byte-identical.
- ORM strict default-on-in-debug may surprise upgraders → escape hatch +
  UPGRADE note + doctor mention.
- New deps (nyholm/psr7 + psr bridge) — tiny, MIT, standard.
- Model binding triggers DB on routes that didn't query before — only when a
  Model type-hint + matching placeholder exists (opt-in by signature).
