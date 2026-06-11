# Security audit — Bundles (filesystem / uploads)

Follow-up to the 4.3.1 hardening (which closed only the native **delete** sinks).
An adversarial re-audit of `src/Bundles/` and `src/Security/` found several
still-open path-traversal, stored-XSS, and authorization issues in the upload /
disk surface. This document records what was audited, the fix (or residual) for
each finding, and the host-side guidance that the framework cannot enforce.

## Scope audited

- `src/Bundles/Path.php` — all host-relative path resolution (`files()`,
  `filesRoot()` and the builders that feed read/write/move/delete sinks).
- `src/Bundles/IonUpload.php` — upload store / move / remove / update.
- `src/Bundles/IonDisk.php` — local + S3 disk API (put / putFile / download /
  move / copy / delete / signed-URL).
- `src/Security/UploadValidator.php` — extension allow/deny + magic-byte gate.

## The durable control: centralized containment in `Path`

The **root cause** (FINDING 6) was that `Path::files()` and `Path::filesRoot()`
were pure string concatenation: a caller-controlled `$file` / `$mainFolder` /
`$bucket` containing `..` segments or an absolute prefix escaped the uploads (or
host) root, and *every* downstream consumer (`IonDisk::exists/size/mimeType/
copy/move`, `IonUpload::moveLocal/moveUrl`, …) inherited that escape.

Containment is now enforced **once, at the source**, in `Path::assertContained()`:

- **Return contract — fail-closed by throwing.** A traversal/absolute argument
  throws `RuntimeException` (matching the 4.3.1 delete-sink contract). Throwing
  is fail-closed and makes every caller safe by construction; a "safe sentinel"
  return was rejected because these methods are called in hundreds of places and
  a silently-wrong path is more dangerous than a hard failure.
- **Rejected:** any `..` path segment (anywhere), a leading `/`, a UNC `\\…`
  prefix, a Windows drive `X:\`/`X:/`, or an embedded null byte.
- **Allowed (unchanged):** legitimate nested *relative* subpaths such as
  `avatars/2024/x.jpg` — only traversal/absolute escapes are rejected, never
  legitimate directory structure.

Tests: `tests/Feature/PathContainmentTest.php`
(`Path::files('../../etc')` throws; `Path::files('avatars/2024/x.jpg')` returns
the rooted path unchanged; same for `filesRoot()` incl. the `mainFolder`/`bucket`
arguments).

## Findings

### FINDING 6 — `Path::files()` / `filesRoot()` traversal (root cause) — FIXED
Centralized containment as above. This single control closes the move/copy/
exists/size/mimeType data flows that route through `Path` (incl.
`IonDisk::copy()/move()` and `IonUpload::moveLocal()`).
Tests: `PathContainmentTest`, plus the move/copy cases in
`tests/Feature/UploadMoveContainmentTest.php`.

### FINDING 1 — request-controllable upload TARGET directory written without containment — FIXED
`IonUpload::store()` (`$file->move()`), `IonDisk::put()` →
`handleLocalUpload()` (`$file->move()`), and `IonDisk::putFile()`
(`"$userProvidedPath/$randomName"`) all took the destination directory from the
caller and wrote without a guard.

- `IonUpload::isWriteTargetContained()` / `IonDisk::isWriteTargetContained()`
  guard the real-filesystem move targets: reject `..`/null-byte, and (FINDING 8)
  canonicalize the **parent**; when a local disk root is configured the
  canonical parent must stay inside it.
- `IonDisk::isWriteKeyContained()` guards `putFile()`'s Flysystem **key**
  (relative to the disk root): reject `..`/null-byte/absolute prefixes.

A `../../`-escaping target is rejected with **nothing written outside** the
intended root; legitimate (existing) subdir targets still store.
Tests: `tests/Feature/UploadWriteContainmentTest.php`
(`IonUpload::store()` / `IonDisk::put()` / `IonDisk::putFile()` escape cases +
the legitimate-subdir cases; existing `IonUploadTest`/`IonDiskTest` stay green).

### FINDING 2 — `move()`/`copy()` and `moveLocal()`/`moveUrl()` containment — FIXED
- `IonDisk::move()`/`copy()` resolve **both** source and destination through
  `Path::files()`/`filesRoot()`, which now contain (FINDING 6). `copy()`'s local
  branch also had a latent `$defaultOptions->get()` null-deref (it assumed
  options were always present); it now mirrors `move()`'s null-safe handling.
- `IonUpload::moveLocal()` ends route through `Path::files()` → contained.
- `IonUpload::moveUrl()`: the `$file_name` derived from the **last segment of an
  attacker-supplied URL** is now `basename()`-reduced (as `update()` already
  does) before it reaches `Path::files()`/`moveLocal()`.

Tests: `tests/Feature/UploadMoveContainmentTest.php`.

### FINDING 3 — `IonDisk::download()` arbitrary write destination — FIXED
The `$downloadPath` was opened with a raw `fopen($downloadPath, 'wb')` with no
containment, so a traversal payload could drop a file anywhere on disk.
`isDownloadDestAllowed()` now rejects `..`/null-byte payloads and, when the
destination's parent exists, requires it to live under the configured local disk
root or the system temp dir; a non-existent parent with no traversal segment is
allowed to be created. The `fopen()` result is also checked for `false`.
The stored **source key** is read through Flysystem, whose v3 path normalizer
already rejects `..` traversal (legitimate nested keys are preserved).
Tests: `tests/Feature/UploadWriteContainmentTest.php`
(traversal destination rejected; legitimate temp destination still written);
existing `IonDiskDownloadTest` stays green.

### FINDING 4 — active-content extensions → stored XSS — FIXED
`UploadValidator::DENY` gained `svg, svgz, xml, html, htm, xhtml, js, mhtml`
(`shtml` was already denied). These execute script/markup when served inline
from `public/uploads`, so they are rejected **even if a host allow-lists them**.
The now-unreachable `svg` entry was removed from the default MIME map.
Test: `tests/Unit/Security/UploadValidatorTest.php` →
`FINDING 4: rejects active-content extensions served from web uploads`.

### FINDING 5 — magic-byte gate failed OPEN for unmapped extensions — FIXED
`expectedMimes()` returning `null` (an allow-listed extension with no MIME
mapping) previously made `isContentValid*()` return `true` — bypassing the
content gate. It now **fails closed**: an allow-listed-but-unmapped extension is
rejected; hosts must register a content signature via `app.uploads.mime_map`.
All default-allowed extensions (`jpg…zip`) are mapped, so no default upload
breaks. docx/xlsx are accepted against `application/zip` **and** their OOXML
MIME types — grounded against `finfo`, which reports `application/zip` for a real
OOXML container, so legitimate office uploads keep working.
Tests: `tests/Unit/Security/UploadValidatorTest.php` →
`FINDING 5: an allow-listed extension with NO mime mapping fails closed`,
`… adding a mime_map entry … makes it pass again`,
`… a real .docx (OOXML zip) passes …`.

### FINDING 7 — `IonDisk::getSignedUrl()` returned an UNSIGNED permanent URL — FIXED
The presign call was commented out, so the method returned a permanent,
unsigned `getObjectUrl()` — an authorization bug (callers expect a time-limited
credential). **Decision: keep the name, make it actually presign.** The AWS SDK
(`aws/aws-sdk-php`, pulled in by `league/flysystem-aws-s3-v3`) exposes
`createPresignedRequest($command, $expiry)`; the method now returns
`(string) $request->getUri()` — a real signed, expiring URL (`X-Amz-Signature`,
`X-Amz-Expires`). An `int` seconds value is normalized to the SDK's relative
expiry form.

Also FINDING 7: several methods (`getSignedUrl`, `getUrl`, `exists`, `size`,
`mimeType`, `createDirectory`, `copy`, `move`) mutated the static
`self::$bucket`/`self::$basePath` from per-call options and never reset them,
bleeding the override across requests in worker mode. A shared
`applyOptionOverrides()` helper now applies the per-call override and restores
the previous static state in a `finally` block — the per-call bucket-override
feature is preserved with no cross-request bleed.
Tests: `tests/Feature/IonDiskSignedUrlTest.php`
(presigned URL is signed/time-limited; a per-call bucket override does not bleed
into the next call). Presigning is a local HMAC, so it is testable offline with
dummy credentials.

### FINDING 8 — write-side canonicalization (residual + code guard) — ADDRESSED
The write-side guards (Findings 1/3) canonicalize the **parent** directory and
do not pass on a `..`-bearing path whose `realpath()` is `false`: a `..` segment
is rejected by the cheap string check *before* any `realpath()`, so a
non-existent target can never bypass containment via the weak `..`-string-only
path. A parent that exists must canonicalize under the allowed root.

## Residual risks (host policy / out of framework scope)

- **TOCTOU / symlink races.** Containment canonicalizes at check time; a
  sufficiently privileged local attacker who can create/replace a symlink
  between the check and the write could still redirect it. Mitigation is
  OS-level (uploads dir not writable by untrusted users; `open_basedir`; no
  attacker-writable parent of the uploads/disk root). The framework does not
  `O_NOFOLLOW`-open.
- **S3 bucket allow-listing is host policy.** `getSignedUrl()`/`getUrl()` accept
  a per-call `bucket` override; the framework does **not** allow-list buckets.
  Hosts must **never** pass request input as the bucket.
- **`getSignedUrl()` expiry** defaults to 1h; hosts should pass the shortest
  workable expiry for sensitive objects.

## Host guidance (do / don't)

- **Never** add `svg`, `html`, `htm`, `xhtml`, `js`, `xml`, `svgz`, `mhtml`,
  `shtml` (or any active-content type) to `app.uploads.allowed` — the DENY gate
  rejects them anyway, and serving them inline from `public/uploads` is XSS.
  Serve user uploads from a cookieless domain / with
  `Content-Disposition: attachment` where possible.
- When you allow an **uncommon** extension, also add its content signature to
  `app.uploads.mime_map` — otherwise the (now fail-closed) magic-byte gate
  rejects it.
- **Never** pass request-controlled input as the `bucket` (or `basePath`)
  option to `IonDisk` methods.
- Treat `IonDisk::getSignedUrl()` as returning a **time-limited** credential
  (it now genuinely does) — do not cache or persist it as if it were permanent.
