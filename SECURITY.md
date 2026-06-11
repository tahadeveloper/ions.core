# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 4.x | Yes — active development; all security fixes |
| 3.0 | Yes — security fixes |
| 1.8 | Security fixes only |
| < 1.8 | No |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security problems.

Report vulnerabilities privately via one of:

- **GitHub private vulnerability reporting** (preferred):
  [Security advisories for tahadeveloper/ions.core](https://github.com/tahadeveloper/ions.core/security/advisories/new)
- **E-mail** the repository owner: `taha.developer@outlook.com`
  (subject line starting with `[SECURITY]`)

Include, where possible: the affected version(s)/commit, a description of the
issue and its impact, and a proof-of-concept or reproduction steps.

## What to Expect

- **Acknowledgement** of your report within **72 hours**.
- An **assessment and severity triage** within **7 days**.
- A **fix or mitigation** for confirmed issues targeted within **30 days**,
  released for all supported versions above and credited to the reporter
  (unless you prefer to remain anonymous).
- Coordinated disclosure: we ask that you give us the opportunity to release
  a fix before publishing details.

## Scope Notes

- Hardening defaults (session cookies, CORS, security headers, upload
  content validation, log redaction) are documented in `UPGRADE-4.1.md` and
  `docs/config.md`. Configuration that intentionally weakens a default
  (e.g. `cookie_secure => false`) is not considered a framework
  vulnerability.
- The filesystem/upload bundle audit (path-traversal containment in
  `Path`/`IonDisk`/`IonUpload`, SVG/active-content deny, fail-closed upload
  content validation, S3 presigned-URL fix) and its residual risks / host
  guidance are documented in
  [`docs/security-audit-bundles.md`](docs/security-audit-bundles.md).
- Dependency advisories are monitored via `composer audit` in CI and
  Dependabot.
