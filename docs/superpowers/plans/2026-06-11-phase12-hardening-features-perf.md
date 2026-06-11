# Phase 12 — Hardening · Debt · Features · Perf Implementation Plan (→ 4.5.0)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. MASTER plan — ground each sub-phase immediately before executing. Gates per push: `php83 vendor/bin/pest` zero warnings + `phpstan.neon` + `phpstan-core.neon` (0) + `php83 vendor/bin/php-cs-fixer fix --dry-run` (0 files); then watch CI (`gh run view`) for the MySQL job (table-collision via dropIfExists, no quoted-identifier assertions). Baseline 1208 tests. Run everything via `php83`.

**Goal:** Execute the 4.4.0 post-release enhancement list: legacy-Bundles security audit, PHPStan baseline burndown, 2FA/TOTP, email verification, HTTP response caching, worker-mode promotion, Kernel decomposition — released as 4.5.0. Storage consolidation deferred to 5.0.

**Spec:** `docs/superpowers/specs/2026-06-11-phase12-hardening-features-perf-design.md`.

---

## 12.1 Legacy Bundles security audit
Adversarial multi-finding review of every fs sink in src/Bundles/{IonDisk,IonUpload,Path} + the upload validation pipeline (traversal, request-controlled input, symlink, TOCTOU, S3-direct, magic-byte bypass). Fix each confirmed issue test-first (containment guards per the 4.3.1 pattern). Deliverable: `docs/security-audit-bundles.md` (or SECURITY.md section) recording checks + residuals. Commit(s): `security(bundles): <finding>` per fix + `docs(security): bundles audit notes`.

## 12.2 PHPStan baseline burndown
Drive `phpstan-baseline.neon` toward empty by FIXING types (generics/nullables/annotations), not adding ignores. Clear/reduce Auth/Guard; raise `phpstan.neon` to level 8 if the baseline empties (else document the irreducible vendor-seam remainder). Commit: `chore(phpstan): burn down baseline; raise main to level <N>`.

## 12.3 Two-factor (TOTP)
`Ions\Auth\TwoFactor` (RFC 6238, dependency-free base32+HMAC preferred): generateSecret/qrUri/verify(window)/recovery codes. Building blocks + one example over a prescribed controller. Migration-stub for host columns. Tests: RFC 6238 vectors, drift, replay, recovery single-use. Docs. Commit: `feat(auth): TOTP two-factor — verifier, recovery codes, otpauth URI`.

## 12.4 Email verification
`Ions\Auth\EmailVerification` on signed URLs + Notifications + form flow: sendVerification/verify + a `verified` middleware + a VerifiesEmail contract. Host wires email_verified_at. Tests: verify flips timestamp, tampered/expired rejected, middleware gates, resend throttled. Docs. Commit: `feat(auth): email verification — signed links, verified middleware`.

## 12.5 HTTP response cache
`Ions\Http\ResponseCache` + `cache.response` middleware (opt-in): cacheable GET full-page cache (200 only, no session/auth), ETag/Last-Modified/304, Cache-Control helpers, debug/private/non-GET bypass, TTL + `cache:clear-responses`. Extend bench/bench.php (cached vs uncached, real numbers). Tests: 2nd-hit-from-cache, 304, never-caches-auth/session/non-200, debug bypass. Docs. Commit: `feat(http): response caching middleware + ETag/304 + cache:clear-responses`.

## 12.6 Worker-mode promotion
Soak the leak matrix across NEW subsystems (Gate user, flash, scheduler binding, trusted-proxy/host statics, ORM-strict flags, query log, request-id); fix leaks in resetForRequest(); FrankenPHP + RoadRunner recipes; doctor readiness note; remove @experimental ONLY after the matrix is green. Tests: multi-subsystem 2-request isolation matrix. Docs (worker-mode.md). Commit: `feat(runtime): promote worker mode to stable — multi-subsystem isolation matrix`.

## 12.7 Kernel decomposition
PURE refactor, zero behavior/API change (all 1208 tests green, no test edits beyond moved internals). Smallest safe extractions, each its own commit + review + full suite: e.g. RouteCapture, MiddlewareStack, RequestGate (proxies/hosts/maintenance/health). Kernel delegates. Commit(s): `refactor(kernel): extract <Collaborator> (no behavior change)`.

## 12.8 Release 4.5.0
CHANGELOG [4.5.0], UPGRADE-4.5 (middleware additions, response-cache opt-in, worker-mode stable), README/best-practices/docs, bench numbers, fact-check review, merge, push, tag 4.5.0 (user confirms push).

## Self-review
Spec 12.1–12.8 covered task-per-section; grounding-first where surfaces are legacy/unknown (Bundles sinks, baseline entries, Sentinel types, Kernel internals); breaking work (Storage) explicitly deferred to 5.0; risks carried (hot-path refactor discipline, cache correctness, TOTP vectors, worker leak matrix).
