# Changelog — 1.x

All notable changes to the `1.x` (pre-v2) line of `ionzile/core`.

The `1.x` line exists to deliver **security fixes to the legacy codebase** without the breaking changes of v2. For the modernized framework see the `main` branch / `v2.0.0` (`CHANGELOG.md` there + `UPGRADE-2.0.md`).

This project adheres to [Keep a Changelog](https://keepachangelog.com) and [Semantic Versioning](https://semver.org).

## [1.1.0] - 2026-06-09 — Security hardening backport

Backports the critical security fixes from the v2 modernization onto the legacy 1.x codebase, kept surgical (no v2 architecture — no container/middleware/pipeline). PHP 8.2+.

### Security
- **Broken JWT replaced (critical).** `Ions\Security\Jwt` (new) issues/verifies **HMAC‑SHA256** tokens with expiry, issuer/audience binding, `jti`, and a `typ=access` guard. The previous `AppKeys` implementation generated an RSA keypair and used the **public** key as the HMAC secret, with **no expiry and no user binding** (any token was valid forever for everyone). `AppKeys` is now a deprecated shim delegating to `Jwt`; `createKey()` writes a 32‑byte random secret to `var/app.key` (chmod 0600) — no more RSA/`key.pem`. Requires `lcobucci/jwt:^5`.
  - Note: the JWT *primitive* is now sound. App‑level callers should pass the authenticated user id to `AppKeys::createJWT($subject)` for per‑user binding (the no‑arg default remains app‑id‑scoped; see the `@deprecated` note).
- **Upload RCE closed (critical).** `Ions\Security\UploadValidator` (new) enforces an extension allow‑list (and a hardcoded deny‑list incl. `php`/`phtml`/`phar`/…). `IonUpload::store()`, `IonDisk::put()`, and `IonDisk::putFile()` now reject disallowed extensions **before** processing and store under a safe (server‑derived) extension — the client‑supplied extension is never trusted. (`verot/class.upload.php` is retained on 1.x but gated.)
- **Query‑filter injection closed.** `QueryBuilder::allowFilters()` now allow‑lists by **default** (`allow_all` defaults to `false`) and is **array‑only** (the old string/variadic form that could silently bypass the allow‑list is gone — misuse now fails loud). Opt out explicitly with `allowAllFilters()`.
- **Spoofable host check replaced.** The `Host`‑header‑vs‑`APP_URL` check (trivially spoofable) is removed in favour of Symfony trusted hosts — set `config('app.trusted_hosts')` (regex patterns, no delimiters).
- **Hardening response headers.** `Ions\Security\SecurityHeaders` applies `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-XSS-Protection`, and a `Content-Security-Policy` (default `default-src 'self'`; override via `config('app.security.csp')`) to every response.

### Changed
- `lcobucci/jwt` `^4.3` → `^5.0` (+ `lcobucci/clock`) — required for the secure JWT.

### Notes / not backported (v2‑only)
- CSRF enforcement, the container/middleware pipeline, `Kernel::handle()`, controller‑returns‑Response, Twig‑only views, and the Illuminate 11 / Symfony 7 / Monolog 3 upgrades are **v2 features** — not applicable to the 1.x architecture. Upgrade to v2 for those (see `UPGRADE-2.0.md` on `main`).
