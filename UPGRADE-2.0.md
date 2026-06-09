# Ions Framework — v2 Upgrade Guide

This document tracks breaking changes and deprecations introduced during the v2 modernisation phases (Phases 2–5). Entries are additive; newer items appear first.

---

## Phase 5 — Auth Subsystem (branch `phase5-tokens-throttle`)

### Static `Guard` / `Guard*` classes are deprecated (BC shim retained)

`Ions\Auth\Guard\Guard`, `GuardUser`, `GuardRole`, and `GuardControl` are now marked
`@deprecated`. They continue to work exactly as before (no behaviour change), but new
code should inject the `Ions\Auth\Contracts\UserProvider` abstraction instead.

**Why:** Phase 5 introduced a pluggable `UserProvider` layer
(`SentinelUserProvider` and `EloquentUserProvider`). The static `Guard` facade couples
callers to Sentinel and cannot benefit from the new abstraction, rate-limiting
integration, or future backend swaps.

**Migration:**

```php
// Before (deprecated):
use Ions\Auth\Guard\Guard;
$result = Guard::login(['email' => $email, 'password' => $password]);

// After (recommended):
/** @var \Ions\Auth\Contracts\UserProvider $provider */
$provider = app('user_provider');
$user = $provider->retrieveByCredentials(['email' => $email, 'password' => $password]);
$ok   = $user && $provider->validateCredentials($user, ['password' => $password]);
```

Sentinel-specific operations (activation, reminders, throttling) that have no equivalent
on the `UserProvider` interface must still go through the Sentinel facade directly (or
`SentinelUserProvider`'s extended API) until a higher-level abstraction is added.

### CSRF enforcement on web routes

`CsrfMiddleware` is now part of the default web middleware stack. All state-changing
requests (`POST`, `PUT`, `PATCH`, `DELETE`) on web routes must include a valid CSRF
token. Use `ionToken()` in Twig templates or include the `_ion_token` hidden field in
forms. Opt-out: set `app.csrf.enabled = false` in `config/app.php`.

### JWT: refresh tokens + `jti` revocation

`Jwt::issue()` now accepts a `type` parameter (`'access'` / `'refresh'`). Access tokens
carry `typ=access`; refresh tokens carry `typ=refresh`. `Jwt::verify()` rejects the
wrong type. The `jti` claim is now checked against a revocation deny-list backed by the
`cache` binding. Configure a persistent cache driver for revocations to survive restarts.

### JWT: configurable clock leeway

`Jwt::verify()` now accepts a `$leeway` parameter (default: value of
`config('app.jwt.leeway')`, fallback 0 seconds). Set `app.jwt.leeway` in
`config/app.php` to tolerate NTP clock skew between services.

### Login rate-limiting middleware

A new `RateLimitMiddleware` (route alias `throttle`) is available. Add it to API routes
that accept login or sensitive payloads. On breach it returns HTTP 429 with a
`Retry-After` header.

---

## Phase 4 — Illuminate Upgrade

### `redbean` database engine removed

The `redbean` engine key in `config/database.php` is silently ignored (logs a
deprecation notice). Remove it and migrate to the `db` (Eloquent) engine.

---

## Phase 3 — HTTP / Routing / Controllers

### Smarty removed

`smarty/smarty` is no longer a dependency. Twig is the sole view engine. Port any
Smarty templates to Twig.

### `MRoute` facade removed

Use `Ions\Bundles\Route` directly.

### `display()` now returns (no implicit exit)

Controllers that called `$this->display($json)` expecting an implicit `exit()` must
now `return $this->display($json)`.

---

## Phase 2 — Container / Middleware

### `app.providers` config key

Setting `app.providers` REPLACES the default provider list. If you set it, include the
framework defaults (`DatabaseProvider`, `FilesystemProvider`, `ViewProvider`, etc.) or
explicitly omit only the ones you want to swap.

### Static service access deprecated

Singleton-based static helpers (where a container binding now exists) carry
`@deprecated` docblocks pointing to the container equivalent. They remain functional.
