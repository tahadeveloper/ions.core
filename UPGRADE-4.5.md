# Upgrading to 4.5

4.5.0 is additive for most hosts. The headline work is the Phase 12 feature
set — TOTP two-factor auth, email verification, and opt-in HTTP response
caching — plus the legacy `Bundles/` security audit and the promotion of worker
mode to stable. These are catalogued in the
[CHANGELOG 4.5.0 section](CHANGELOG.md#450---2026-06-11), with full guides in
[docs/two-factor.md](docs/two-factor.md),
[docs/email-verification.md](docs/email-verification.md),
[docs/response-cache.md](docs/response-cache.md) and
[docs/security-audit-bundles.md](docs/security-audit-bundles.md). This document
covers only the behavior changes you may need to act on.

No new composer dependencies install with this upgrade. The 2FA and
email-verification features are dependency-free (email verification reuses the
existing signed-URL and notification machinery).

## Behavior changes

### Upload content validation is now fail-closed

`Ions\Security\UploadValidator` (used by both `IonUpload` and `IonDisk`) now
**rejects** an upload whose extension is on the allow-list but has **no entry
in the MIME map**. Previously such an extension was accepted with no
content-signature check (fail-open); now the absence of a signature means the
file cannot be validated, so it is refused (fail-closed).

**Action:** if you allow-list an extension that is not in the built-in MIME map,
add a mapping under `app.uploads.mime_map` (merged over the framework defaults),
e.g.:

```php
// config/app.php
'uploads' => [
    'allowed'  => ['pdf', 'png', 'csv'],
    'mime_map' => [
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ],
],
```

Extensions already covered by the defaults (common images, documents, archives,
media) need no change. Note also that `svg`, `svgz`, `xml`, `html`, `htm`,
`xhtml`, `js` and `mhtml` are now on the hard-coded deny-list and can **never**
be accepted, even if allow-listed — these were stored-XSS vectors.

### `Path::files()` / `Path::filesRoot()` reject `..` / absolute arguments

As part of centralizing upload/disk containment, `Path::files()` and
`Path::filesRoot()` now validate their path argument: a `..` segment, an
absolute path, or a null byte throws a `RuntimeException` rather than resolving.
Legitimate nested **relative** subpaths still work exactly as before.

**Action:** if your host passes user-influenced or absolute values into
`Path::files()` / `Path::filesRoot()`, stop — pass a relative subpath inside the
uploads/disk root instead. In particular, a host that *computed an absolute
path* (e.g. `Path::files('/var/www/app/public/uploads/x')` or
`Path::files(base_path('uploads').'/x')`) must now pass only the **root-relative
subpath** (`Path::files('x')` / `Path::files('avatars/2024/x.jpg')`) and let
`Path` resolve it under the root. This is the same containment that closes the
write/move/copy/download path-traversal vectors in `IonUpload`/`IonDisk`.

### `IonDisk::getSignedUrl()` now returns a presigned, expiring URL

`IonDisk::getSignedUrl($path, $expirationTime = 3600, $defaultOptions = null)`
now issues a **genuinely signed, time-limited** URL. Previously it returned an
unsigned, effectively permanent URL — so anything relying on that link being
permanently public will now see it expire (default 1 hour).

**Action:** if you stored or embedded `getSignedUrl()` output expecting a
permanent public link, either generate the URL on demand (and tune
`$expirationTime`) or use a genuinely public path/ACL for assets that must stay
public. No action is needed for the intended use — short-lived signed access to
private objects.

### HTTP response caching is opt-in and never caches auth/session

The new `cache.response` middleware (`Ions\Http\Middleware\CacheResponseMiddleware`)
is **opt-in per route** — nothing is cached unless you attach it. It only ever
stores safe, shareable responses: idempotent **GET 200s** with no session data,
no resolved auth user (`auth_user` / `auth_user_id` request attributes) and no
`Set-Cookie`. Per-client and hop-by-hop headers are stripped, and a stored entry
is revalidated with `ETag` / `304 Not Modified`. Auth, session and flash
responses are **never** cached, so there is no risk of leaking per-user data,
CSRF tokens or flash messages across clients.

**Action:** attach `cache.response` only to routes that render the same payload
for every client (public pages, anonymous API reads). Nothing changes for
routes you do not opt in.

### `cache:clear-responses` is tag-isolated by default

`cache:clear-responses` purges **only** the response-cache tag on a tag-capable
store (redis/memcached), leaving JWT revocations, rate-limit counters and
forgot-password throttles that share the store untouched. On a store with **no
tag support** (file/database) it cannot scope the purge, so without `--force`
it is a **no-op** (it refuses to flush the whole store) and reports that it did
nothing. Passing `--force` performs a full store flush — which **also** wipes
those shared JWT revocations and throttles.

**Action:** prefer a tag-capable response-cache store. Only pass `--force` when
you understand it clears the entire store, and never wire it into routine
deploys on a shared non-tag store.

### Worker mode is stable (no longer experimental)

`Ions\Runtime\WorkerRunner` and `Kernel::resetForRequest()` are promoted from
experimental to **stable**. `WorkerRunner` is no longer marked `@experimental`;
per-request state isolation is proven by a multi-subsystem leak matrix and
checked by `ions doctor`. The API is unchanged — this is a support-level
promotion, not a signature change.

**Action:** none required. If you previously avoided worker mode because it was
experimental, the [FrankenPHP / RoadRunner recipes](docs/worker-mode.md) are now
the supported path. FrankenPHP and RoadRunner remain doc-only; neither is a
dependency of `ionzile/core`.

## New in 4.5

Beyond the behavior changes above, 4.5 is the Phase 12 feature release: TOTP
two-factor auth (`Ions\Auth\TwoFactor` — RFC 6238 verifier, recovery codes,
otpauth URI, replay store), email verification (`Ions\Auth\EmailVerification`,
the `VerifiesEmail` contract, the `verified` middleware and `VerifyEmail`
notification), and opt-in HTTP response caching (`Ions\Http\ResponseCache` with
ETag/304 revalidation, ≈ 10–12× faster on cache hits). It also closes the legacy
`Bundles/` security audit and promotes worker mode to stable. Internally, the
PHPStan baseline is now empty and `Kernel` was decomposed into focused
collaborators (`TrustedProxies` / `JwtFactory` / `MiddlewareStack` /
`ControllerResolver`) with no behavior change. See the
[CHANGELOG 4.5.0 section](CHANGELOG.md#450---2026-06-11),
[docs/two-factor.md](docs/two-factor.md),
[docs/email-verification.md](docs/email-verification.md),
[docs/response-cache.md](docs/response-cache.md) and
[docs/security-audit-bundles.md](docs/security-audit-bundles.md) for the full
reference.
