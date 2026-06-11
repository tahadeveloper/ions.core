# Phase 12 — Hardening · Debt · Features · Perf (Design → 4.5.0)

User directive 2026-06-11: "next phase do all you found" — the enhancement
list from the 4.4.0 post-release review. All items additive unless flagged;
release framing **4.5.0**. Breaking items (Storage consolidation) explicitly
DEFERRED to 5.0. Execution: the established cadence (TDD, two-stage subagent
review, merge-as-you-go). Gates per push: `php83 vendor/bin/pest` zero
warnings + `phpstan.neon` + `phpstan-core.neon` (both 0) + `php83 vendor/bin/php-cs-fixer fix --dry-run`
(0 files); then watch CI for the MySQL job (table-collision/quoting portability).

## 12.1 Legacy Bundles security audit + hardening

- Motivation: the 4.3.1 critical (request-controlled filename → arbitrary
  file deletion) lived in `src/Bundles/{IonUpload,IonDisk,Path}` — the oldest,
  least-level-8-covered corner. One critical there warrants a deliberate pass.
- Scope: audit EVERY filesystem sink in IonDisk/IonUpload (unlink/rmdir/
  fopen/rename/copy/file_put_contents/file_get_contents) and Path's path
  builders for: path traversal, request-controlled inputs, symlink escape,
  TOCTOU, S3-direct methods (getUrl/getSignedUrl/getObject — un-interceptable
  by Storage::fake). Harden any confirmed issue (containment guards like
  4.3.1); document residual risks. Also audit the upload pipeline
  (UploadValidator magic-bytes, extension allow/deny, mime_map) for bypasses.
- Deliverable: an adversarial review (multi-finding), each confirmed issue
  fixed test-first; a short `docs/security-audit-bundles.md` (or a section in
  SECURITY.md) recording what was checked + residuals.
- Acceptance: no unguarded request-controlled fs sink remains; new guards
  test-covered; existing IonDisk/IonUpload tests green.

## 12.2 PHPStan baseline burndown → level 8

- `phpstan-baseline.neon` is 151 lines; ~18 entries are `src/Auth/Guard`
  (Sentinel wrapper), the rest scattered (Bundles/Cache, RouteListCommand,
  migrate, Kernel). Goal: drive the baseline toward empty so all of `src/`
  passes level 8 (the `phpstan.neon` level could then rise from 5 → 8).
- Approach: fix the real type issues (generics, nullables, missing
  param/return types) rather than expand ignores; for genuinely-untyped
  vendor seams (Sentinel), add precise `@var`/`@phpstan-*` annotations, not
  blanket suppressions. If a residue is irreducible (vendor types), keep a
  MINIMAL, commented baseline. Raise `phpstan.neon` to level 8 if the baseline
  empties; otherwise document why the remainder stays.
- Acceptance: baseline materially smaller (target: Auth/Guard cleared or
  reduced to documented vendor-seam entries); main config at the highest level
  the remaining debt allows; zero new ignores for first-party code; suite green.

## 12.3 Two-factor auth (TOTP)

- `Ions\Auth\TwoFactor` (RFC 6238 TOTP — implement against a small, audited
  base32 + HMAC, OR a tiny dependency if cleaner; prefer dependency-free given
  the security context, ground the choice): `generateSecret()`, `qrUri($secret,
  $label, $issuer)` (otpauth:// URI for authenticator apps), `verify($secret,
  $code, $window = 1)` (timing-safe, ±window drift). Recovery codes
  (generate/hash/consume). Storage: host-side (a `two_factor_secret`/
  `two_factor_recovery` columns convention + a migration stub) — the framework
  provides the verifier + helpers, the host wires persistence.
- HTTP surface: an opt-in `Ions\Auth\Http\TwoFactorController` (enable/confirm/
  challenge/disable) OR documented building blocks — decide at execution
  (prefer building blocks + one example to avoid over-prescribing the user
  model). Integrate with the JWT/login flow as a documented pattern (2FA gate
  after password, before token issue).
- Acceptance: TOTP verify against known RFC 6238 test vectors; window drift;
  replay within a step rejected (optional last-used tracking); recovery code
  single-use; docs + example.

## 12.4 Email verification flow

- Built on signed URLs (8.5) + Notifications (8.5) + the form flow (10.3):
  `Ions\Auth\EmailVerification` — `sendVerification($notifiable)` (a signed,
  expiring `verify.email` route link via Notification mail channel),
  `verify($request)` (validates the signed URL + marks verified), a
  `verified` route middleware (403/redirect when the user's email is
  unverified). Host wires the `email_verified_at` column + the
  `MustVerifyEmail`-style contract (`Ions\Auth\Contracts\VerifiesEmail`?).
- Acceptance: signed link verifies + flips the timestamp; tampered/expired
  link rejected; middleware gates unverified users; resend throttled (reuse
  the per-email limiter); docs + example.

## 12.5 HTTP response caching (perf — biggest unclaimed win)

- `Ions\Http\ResponseCache` + a `cache.response` middleware (opt-in per route
  / group): full-page cache for cacheable GETs (configurable: only when no
  session/auth, only 200, respect Cache-Control), keyed by method+URL(+Vary),
  stored in the existing cache store with TTL; ETag/Last-Modified +
  conditional-request (304) handling; `Cache-Control`/`Expires` emission
  helpers. Bypass on debug, on non-GET, on responses marked private/no-store
  (the 10.x redirect/health work already sets these). Invalidation: TTL +
  a documented `cache:clear-responses` (or tag-based purge if the store
  supports tags).
- Benchmark: extend `bench/bench.php` to measure a cached vs uncached page;
  report real numbers (the 8.1 discipline — no claimed %).
- Acceptance: cacheable GET served from cache on the 2nd hit (proven, not
  claimed); 304 on matching ETag; never caches authenticated/session/non-200;
  debug bypass; benchmark numbers; docs.

## 12.6 Worker-mode promotion (perf — out of experimental)

- The 8.2 `WorkerRunner` is `@experimental`. Promote it: soak-test request
  isolation harder (state-leak matrix across the new 8.x–11.x subsystems —
  Gate user, flash, scheduler binding, trusted-proxy statics, ORM-strict
  flags, query log, request-id), fix any leaks found in `resetForRequest()`,
  add a FrankenPHP + a RoadRunner recipe, remove the `@experimental` marker,
  and a `doctor` readiness note. Memory-growth guard already exists
  (`maxRequests`).
- Acceptance: a multi-subsystem leak matrix (2+ sequential requests touching
  Gate/flash/scheduler/ORM-strict/proxies → no cross-request bleed); recipes
  documented; experimental marker removed only after the matrix is green.

## 12.7 Kernel decomposition (maintainability)

- `src/Foundation/Kernel.php` is ~1,244 lines (the static god-object absorbed
  routing capture, middleware assembly, boot, trusted proxies/hosts,
  maintenance/health gates, response normalization). Extract cohesive,
  independently-testable collaborators WITHOUT changing the public
  `Kernel::boot()/handle()/make()` surface or behavior (pure refactor, all
  1208 tests must stay green byte-for-byte): e.g. `RouteCapture`
  (captureRoute/buildRouteCollection/fallback), `MiddlewareStack`
  (defaultMiddleware/resolveMiddleware/pipeline assembly), `RequestGate`
  (trusted proxies/hosts + maintenance + health early checks). Kernel keeps
  delegating facades. Risk-managed: smallest safe extractions, each its own
  commit + review, full suite between each.
- Acceptance: Kernel materially smaller; extracted classes unit-testable;
  ZERO behavior change (existing suite green, no test edits beyond moved
  internals); no public API change.

## 12.8 Release 4.5.0

- CHANGELOG `[4.5.0]`, UPGRADE-4.5 (only real behavior notes: any 2FA/verify
  middleware additions, response-cache opt-in, worker-mode now stable),
  README/best-practices/docs pass, benchmark numbers, fact-check review at
  the established bar, merge, push, tag 4.5.0 (user confirms push).

## Sequencing

12.1 (security, highest trust) → 12.2 (debt, clean milestone) → 12.3 (2FA) →
12.4 (email verify) → 12.5 (response cache + bench) → 12.6 (worker promotion)
→ 12.7 (Kernel decomposition, risk-managed, near end) → 12.8 release.

## Explicitly DEFERRED to 5.0 (breaking)

- **Storage class consolidation**: `Ions\Filesystem\Storage` vs the
  `Ions\Support\Storage` Illuminate shim — one must win; that's a breaking
  import change → 5.0, not 4.5. Document the trap meanwhile (already in
  docs/testing.md).
- IonDisk/IonUpload full removal in favor of the manager (5.0).

## Risks

- 12.7 Kernel refactor on the request hot path — pure-refactor discipline,
  no behavior/API change, full suite as the oracle, smallest extractions.
- 12.5 response cache correctness (serving stale/private data) — conservative
  defaults (GET + 200 + no session/auth only), debug bypass, explicit opt-in.
- 12.3 2FA crypto — RFC 6238 test vectors as the oracle; timing-safe compare;
  prefer dependency-free with an audited base32/HMAC.
- 12.6 worker leaks — the matrix must cover the NEW subsystems, not just the
  8.2-era ones.
