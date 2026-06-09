# Ions Framework — 4.0.0 Upgrade Guide

This document tracks the breaking changes introduced during the 4.0.0 phase (Phase 7).
This is the upgrade guide for the **3.0.x → 4.0.0** transition. It is a work in
progress; the full guide is completed in Task 7.9.

---

## Quick-reference checklist

| Area | Action required |
|---|---|
| PHP | Upgrade the host runtime to **PHP 8.3+** (this is the breaking change that gates 4.0.0) |
| Illuminate / Laravel | Now on **Illuminate 12** (was 11) — review Eloquent / container / validation deltas |
| Auth — Sentinel | Now on **Cartalyst Sentinel v9** (was v8) — no public API changes required for Ions consumers |

---

## Phase 7 — Coordinated dependency bump (Task 7.1)

### PHP 8.3 minimum (Breaking)

The framework now **requires PHP 8.3** (`composer.json` `require.php` is `^8.3`, and
the build `config.platform.php` is pinned to `8.3`). This is the dominant breaking
change for 4.0.0: hosts still on PHP 8.2 must upgrade their runtime before pulling
`ionzile/core` 4.0.

CI runs the suite on **PHP 8.3 and 8.4**. PHP 8.4 surfaces a small number of
implicit-nullable deprecation notices (in Ions code and in the Sentinel dependency);
these are non-fatal and the full 200-test suite passes on 8.4.

### Illuminate 12 (Breaking — major version)

All `illuminate/*` constraints moved from `^11.0` to `^12.0` (resolved: **v12.62**).
Laravel 12 is a light major: no source changes were required in Ions to adopt it.
The Laravel-12 Rector set (`LaravelSetList::LARAVEL_120`) reports no mandatory
rewrites for the core. Carbon resolves to **3.11**, Symfony stays on **7.x**, and
Monolog stays on **3.x**.

### Cartalyst Sentinel v9 (Breaking — major version)

`cartalyst/sentinel` moved from `^8.0` to `^9.0` (resolved: **v9.0.0**). The Ions
Sentinel adapter (`Auth/Guard/Guard`, `Auth/SentinelUserProvider`,
`Auth/SentinelUserAdapter`) required **no changes** — the native facade, migrations,
hashing, and registration/activation flow are API-compatible for the surface Ions
uses. The `GuardTest` and `SentinelUserProviderTest` suites pass unchanged.

---

*(Further 4.0.0 entries are added in subsequent Phase 7 tasks; the guide is finalised in Task 7.9.)*
