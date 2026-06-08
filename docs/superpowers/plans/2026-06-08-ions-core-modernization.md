# Ions Core Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize `ionzile/core` into a secure, testable, dependency-honest PHP 8.2+ micro-framework with a real DI container, middleware pipeline, hardened auth, upgraded Eloquent, and a full DX toolchain — while keeping the `Ions\` public surface familiar and shipping a documented v2 upgrade path.

**Architecture:** Replace the all-static `Singleton` model with a PSR-11 container (`Ions\Container`) created once at boot and threaded through a PSR-15 middleware pipeline. `Kernel` becomes a thin orchestrator that builds the container, runs service providers, dispatches the request through middleware to a resolved controller, and renders a response. Security concerns (auth, CSRF, CORS, security headers, upload validation) move out of constructors into composable middleware and dedicated, tested services. All third-party packages the core touches become declared Composer dependencies.

**Tech Stack:** PHP 8.2+, Symfony 7.x (Routing, Config, HttpFoundation, Mailer, Translation, Security-CSRF, Yaml), Illuminate 11.x (Database/Eloquent, Validation, Cache, Console, Filesystem), `lcobucci/jwt` 5.x, `league/flysystem` 3.x, Pest 3 + PHPUnit 11, PHPStan 2 (level 6→8), PHP-CS-Fixer 3, Rector 2, GitHub Actions CI.

> **Interim toolchain note (Phase 0 actual):** Pest 3 / PHPUnit 11 cannot be installed yet — `pestphp/pest:^3` requires `nunomaduro/termwind:^2`, which conflicts with the pinned EOL `illuminate/console:v9.52.4` (needs `termwind:^1`). Phase 0 therefore landed on **Pest 2 + PHPUnit 10**. The Pest 3 upgrade is gated on the Illuminate 9→11 upgrade in **Phase 4**; do it there. Likewise `illuminate/container` + `illuminate/support` were pinned to `v9.52.4` to match the other illuminate/* pins (a transitive `9.52.16` broke `new Container()` under PHP 8.2).

---

## How to read this plan

This modernization touches four independent subsystems. To keep each phase shippable and reviewable:

- **Phase 0 (Foundation) and Phase 1 (Security) are fully specified** with bite-sized TDD steps — these are the "deliver first" safety net and the urgent fixes. Execute them directly from this document.
- **Phases 2–6 are specified at the task/file level** with code sketches and acceptance criteria. Each is a self-contained subsystem and **MUST be expanded into its own detailed plan file** (`docs/superpowers/plans/YYYY-MM-DD-<phase>.md`) immediately before that phase is executed, using this section as the spec. This is deliberate: design decisions in Phase 2 (container shape) determine the exact code in Phases 3–5, so writing their micro-steps now would be guesswork.

**Branch/release strategy:** All work happens on a `v2` branch. `main` keeps receiving Phase 1 security hotfixes cherry-picked back as a `1.x` patch line (so production apps get fixes without the breaking changes). v2 ships when Phase 6 completes, with the upgrade guide.

---

## Guiding principles

- **DRY / YAGNI / TDD.** Every behavioral change starts with a failing test. No speculative abstraction.
- **Backward-compatible where cheap, breaking where necessary.** Keep helper function names (`config()`, `app()`, `trans()`, `render()`, `validate()`, `abort()`), keep `Route::get/post/...` and attribute routing, keep the controller lifecycle hook names (`_initState`/`_loadInit`/`_loadedState`/`_endState`). Break the internals (static state, auth, upload) and document each break.
- **Security-first ordering.** The dangerous defects (auth, upload RCE) are fixed before any refactor, behind tests that prove the old behavior was wrong.
- **Self-contained package.** The core declares every dependency it imports. No reliance on the host app to supply transitive packages.
- **Incremental adoptability.** Old static facades become thin shims over the new container so existing apps keep booting during migration.

---

## Target architecture (end state)

```
Kernel::boot()
  ├─ load .env (immutable)
  ├─ build Container (PSR-11)            ← new: Ions\Container\Container
  ├─ register ServiceProviders           ← new: config-driven provider list
  │     ConfigProvider, RoutingProvider, DatabaseProvider,
  │     ViewProvider, AuthProvider, FilesystemProvider, MailProvider
  ├─ boot providers
  └─ run preloads

Kernel::handle(Request): Response          ← was make(), now returns a Response
  ├─ build RouteCollection (php|yaml + attributes)
  ├─ match route → controller + args
  └─ MiddlewarePipeline->handle(Request)   ← new: PSR-15
        ├─ TrustedHostMiddleware           (replaces inline Host check)
        ├─ SecurityHeadersMiddleware
        ├─ CorsMiddleware
        ├─ CsrfMiddleware                  (web group)
        ├─ AuthMiddleware                  (api group: JWT verify + user resolve)
        └─ ControllerDispatcher            (lifecycle hooks + action)
```

Container services (string ids kept stable): `request`, `response`, `session`, `config`, `db`, `db.connection`, `db.schema`, `files`, `filesystem`, `router`, `auth`, `jwt`, `view`, `mailer`, `logger`.

---

## File structure (new + heavily modified)

**New files:**
- `src/Container/Container.php` — PSR-11 container (wraps `Illuminate\Container\Container`, adds typed `get()`).
- `src/Container/ServiceProvider.php` — abstract base (`register()`, `boot()`).
- `src/Providers/*.php` — one provider per subsystem (Config, Routing, Database, View, Auth, Filesystem, Mail).
- `src/Http/Middleware/MiddlewareInterface.php` (or use `Psr\Http\Server\MiddlewareInterface`).
- `src/Http/Middleware/Pipeline.php`
- `src/Http/Middleware/{TrustedHost,SecurityHeaders,Cors,Csrf,Auth}Middleware.php`
- `src/Security/Jwt.php` — replaces the broken `AppKeys` JWT logic.
- `src/Security/UploadValidator.php` — extension/MIME allow-list for `IonUpload`.
- `src/Support/Env.php` — typed env access (replaces scattered `env()`).
- `tests/Unit/**`, `tests/Feature/**`, `tests/Pest.php` (extended), `tests/TestCase.php`.
- Tooling: `phpstan.neon`, `.php-cs-fixer.dist.php`, `rector.php`, `.github/workflows/ci.yml`.
- Docs: `UPGRADE-2.0.md`, `CHANGELOG.md`.

**Heavily modified:**
- `src/Foundation/Kernel.php` — boot/handle split, container-based, returns Response.
- `src/Foundation/RegisterDB.php` → folded into `DatabaseProvider`.
- `src/Foundation/{Api,Base}Controller.php` — lifecycle preserved, auth/input moved to middleware/services.
- `src/Bundles/AppKeys.php` — delegates to `Security\Jwt` (kept as deprecated shim).
- `src/Bundles/IonUpload.php` — calls `UploadValidator`, random + safe extension.
- `composer.json` — declare all deps, upgrade Illuminate, add dev tooling, set `Ions\Tests\` PSR-4 autoload-dev.

---

## Phase 0 — Foundation & safety net

**Why first:** We cannot safely refactor a framework with one trivial test. Phase 0 makes the package dependency-honest, adds the toolchain, and builds a characterization test harness so later phases have a net.

### Task 0.1: Declare all undeclared runtime dependencies

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add the missing packages the core already imports.**

The core imports these but does not require them. Add to `require` in `composer.json`:

```json
"twig/twig": "^3.10",
"smarty/smarty": "^4.5",
"spatie/ignition": "^1.15",
"filp/whoops": "^2.15",
"gabordemooij/redbean": "^5.7",
"verot/class.upload.php": "^2.1"
```

> Note: confirm the exact package names against Packagist before committing (`verot/class.upload.php` provides `Verot\Upload\Upload`; `gabordemooij/redbean` provides `RedBeanPHP\R`). If RedBean/Smarty are being dropped in Phase 4/3, mark them `suggest` instead — but for now they are imported at runtime, so they must resolve.

- [ ] **Step 2: Install and verify the autoloader resolves every imported class.**

Run:
```bash
composer update --no-interaction
vendor/bin/phpunit --version
```
Expected: install succeeds, no "class not found" when autoloading core files.

- [ ] **Step 3: Add a smoke test that every `src/` file is loadable.**

Create `tests/Unit/AutoloadTest.php`:
```php
<?php
use Symfony\Component\Finder\Finder;

test('every core class file is syntactically valid and autoloadable', function () {
    $finder = (new Finder())->files()->in(__DIR__ . '/../../src')->name('*.php');
    foreach ($finder as $file) {
        $code = $file->getContents();
        // helpers.php is a function file, skip class-map assertion for it
        if (str_contains($code, 'namespace Ions')) {
            expect(token_get_all($code))->toBeArray();
        }
    }
})->skip(fn () => !class_exists(\Symfony\Component\Finder\Finder::class), 'symfony/finder not installed');
```

- [ ] **Step 4: Run it.**

Run: `vendor/bin/pest tests/Unit/AutoloadTest.php`
Expected: PASS (or skipped if Finder absent — then `composer require --dev symfony/finder` and re-run to PASS).

- [ ] **Step 5: Commit.**
```bash
git add composer.json composer.lock tests/Unit/AutoloadTest.php
git commit -m "build: declare all runtime dependencies imported by core"
```

### Task 0.2: Add the static-analysis + code-style toolchain

**Files:**
- Create: `phpstan.neon`, `.php-cs-fixer.dist.php`
- Modify: `composer.json` (require-dev + scripts)

- [ ] **Step 1: Require dev tooling.**
```bash
composer require --dev phpstan/phpstan:^2.0 friendsofphp/php-cs-fixer:^3.64 rector/rector:^2.0 pestphp/pest:^3.0 --no-interaction
```

- [ ] **Step 2: Create `phpstan.neon` starting at a tolerant level.**
```neon
parameters:
    level: 4
    paths:
        - src
    excludePaths:
        - src/commands/stubs/*
    treatPhpDocTypesAsCertain: false
```

- [ ] **Step 3: Create `.php-cs-fixer.dist.php`.**
```php
<?php
$finder = PhpCsFixer\Finder::create()->in(__DIR__ . '/src')->in(__DIR__ . '/tests');
return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'declare_strict_types' => false, // enable per-file as we touch them
    ])
    ->setFinder($finder);
```

- [ ] **Step 4: Add composer scripts.**
```json
"scripts": {
    "test": "pest",
    "stan": "phpstan analyse",
    "cs": "php-cs-fixer fix --dry-run --diff",
    "cs:fix": "php-cs-fixer fix",
    "rector": "rector process --dry-run",
    "qa": ["@cs", "@stan", "@test"]
}
```

- [ ] **Step 5: Run the baseline and capture current debt.**

Run: `vendor/bin/phpstan analyse --generate-baseline`
Expected: produces `phpstan-baseline.neon` capturing existing issues so CI stays green while we burn it down. Commit the baseline.

- [ ] **Step 6: Commit.**
```bash
git add composer.json composer.lock phpstan.neon phpstan-baseline.neon .php-cs-fixer.dist.php
git commit -m "build: add phpstan, php-cs-fixer, rector, pest3 toolchain with baseline"
```

### Task 0.3: CI pipeline

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create the workflow.**
```yaml
name: CI
on: [push, pull_request]
jobs:
  qa:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: openssl, zip, pdo_sqlite
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer cs
      - run: composer stan
      - run: composer test
```

- [ ] **Step 2: Commit and push branch to confirm CI runs.**
```bash
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions QA pipeline (php 8.2/8.3)"
```
Expected: CI runs green (baseline absorbs existing phpstan debt; cs may need one `composer cs:fix` commit first — run it and commit if so).

### Task 0.4: Test harness for booting the Kernel against a fixture app

**Why:** Every later phase needs to boot the framework in isolation. The Kernel currently resolves paths 5 levels up to a host app that doesn't exist in this repo. Build a fixture host app under `tests/fixtures/app/` and a `TestCase` that points the Kernel at it.

**Files:**
- Create: `tests/TestCase.php`, `tests/fixtures/app/{config,routes,var,public}/...`
- Modify: `tests/Pest.php` (bind TestCase), `src/Bundles/Path.php` + `src/Foundation/Kernel.php` (make the base path injectable — see Step 2)

- [ ] **Step 1: Write a failing test that boots the Kernel against a fixture.**

Create `tests/Feature/BootTest.php`:
```php
<?php
test('kernel boots against a fixture app and exposes config', function () {
    bootFixtureKernel();
    expect(config('app.name'))->toBe('IonsFixture');
});
```

- [ ] **Step 2: Make the host base path injectable (small, surgical change).**

In `src/Bundles/Path.php`, replace the hardcoded `protected static string $environmentPath = __DIR__ . ...` with a settable base:
```php
protected static ?string $basePath = null;

public static function setBasePath(string $path): void
{
    self::$basePath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

protected static function base(): string
{
    return self::$basePath
        ?? realpath(__DIR__ . str_repeat(DIRECTORY_SEPARATOR . '..', 4)) . DIRECTORY_SEPARATOR;
}
```
Then replace every `realpath(self::$environmentPath)` usage with `rtrim(self::base(), DIRECTORY_SEPARATOR)`. Do the same for `Kernel::$environmentPath` (let `boot()` accept an optional `?string $basePath = null` and call `Path::setBasePath()` when provided).

> This is the one structural change in Phase 0. It is backward compatible: when `setBasePath()` is never called, behavior is identical to today (5-up resolution). Add a unit test asserting the fallback equals the old path.

- [ ] **Step 3: Build the fixture app.**

Create `tests/fixtures/app/config/app.php`:
```php
<?php
return [
    'name' => 'IonsFixture',
    'app_url' => 'http://localhost',
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],
];
```
Create `tests/fixtures/app/config/database.php`:
```php
<?php
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
```
Create empty dirs `tests/fixtures/app/{routes,var/cache,var/logs,var/templates,public/lang}` (add `.gitkeep`).

- [ ] **Step 4: Add the `bootFixtureKernel()` helper to `tests/Pest.php`.**
```php
function bootFixtureKernel(): void
{
    $base = __DIR__ . '/fixtures/app';
    \Ions\Foundation\Kernel::boot($base); // boot() now accepts optional base path
}
```

- [ ] **Step 5: Run the test.**
Run: `vendor/bin/pest tests/Feature/BootTest.php`
Expected: PASS — the Kernel boots, reads fixture config, `config('app.name')` is `IonsFixture`.

- [ ] **Step 6: Commit.**
```bash
git add tests/ src/Bundles/Path.php src/Foundation/Kernel.php
git commit -m "test: bootable fixture app + injectable base path for testing"
```

**Phase 0 acceptance:** `composer qa` green in CI on 8.2 + 8.3; framework boots under test against an in-memory SQLite fixture.

---

## Phase 1 — Security hardening (fully specified, TDD)

Each fix lands with a test that first proves the **current** behavior is insecure, then proves the fix. Phase 1 commits are cherry-pick candidates for the `1.x` hotfix line.

### Task 1.1: Replace broken JWT with a real, expiring, user-bound token service

**Files:**
- Create: `src/Security/Jwt.php`, `tests/Unit/Security/JwtTest.php`
- Modify: `src/Bundles/AppKeys.php` (deprecate → delegate), `composer.json` (`lcobucci/jwt:^5.0`)

**Design:** `Jwt` signs with **HMAC-SHA256 using a dedicated secret** read from `APP_KEY` (env), not from an RSA public key file. Tokens carry `sub` (user id), `iat`, `exp` (TTL from config), `jti`. Validation enforces signature, `exp`, issuer, audience.

- [ ] **Step 1: Write failing tests for issue + verify + expiry + tamper.**

`tests/Unit/Security/JwtTest.php`:
```php
<?php
use Ions\Security\Jwt;

beforeEach(function () {
    $this->jwt = new Jwt(secret: str_repeat('a', 32), issuer: 'ions', audience: 'ions-app', ttlSeconds: 3600);
});

test('issues a token bound to a user id', function () {
    $token = $this->jwt->issue(userId: '42');
    $claims = $this->jwt->verify($token);
    expect($claims->userId)->toBe('42');
});

test('rejects a tampered token', function () {
    $token = $this->jwt->issue('42');
    expect(fn () => $this->jwt->verify($token . 'x'))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects an expired token', function () {
    $expired = new Jwt(str_repeat('a', 32), 'ions', 'ions-app', ttlSeconds: -10);
    $token = $expired->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});

test('rejects a token signed with a different secret', function () {
    $other = new Jwt(str_repeat('b', 32), 'ions', 'ions-app', 3600);
    $token = $other->issue('42');
    expect(fn () => $this->jwt->verify($token))->toThrow(\Ions\Security\TokenException::class);
});
```

- [ ] **Step 2: Run to confirm failure.**
Run: `vendor/bin/pest tests/Unit/Security/JwtTest.php`
Expected: FAIL — `Ions\Security\Jwt` does not exist.

- [ ] **Step 3: Implement `Jwt` and `TokenException`.**

`src/Security/TokenException.php`:
```php
<?php
namespace Ions\Security;
class TokenException extends \RuntimeException {}
```

`src/Security/Jwt.php`:
```php
<?php
namespace Ions\Security;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\Clock\SystemClock;

final class Jwt
{
    private Configuration $config;

    public function __construct(
        string $secret,
        private string $issuer,
        private string $audience,
        private int $ttlSeconds = 3600,
    ) {
        if (strlen($secret) < 32) {
            throw new TokenException('JWT secret must be at least 32 bytes.');
        }
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

    public function issue(string $userId, array $claims = []): string
    {
        $now = new DateTimeImmutable();
        $builder = $this->config->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->audience)
            ->relatedTo($userId)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$this->ttlSeconds} seconds"));
        foreach ($claims as $k => $v) {
            $builder = $builder->withClaim($k, $v);
        }
        return $builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
    }

    public function verify(string $token): Claims
    {
        try {
            $parsed = $this->config->parser()->parse($token);
            $this->config->validator()->assert(
                $parsed,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt(SystemClock::fromSystemTimezone()),
                new IssuedBy($this->issuer),
                new PermittedFor($this->audience),
            );
        } catch (\Throwable $e) {
            throw new TokenException('Invalid token: ' . $e->getMessage(), previous: $e);
        }
        /** @var \Lcobucci\JWT\UnencryptedToken $parsed */
        return new Claims(
            userId: (string) $parsed->claims()->get('sub'),
            all: $parsed->claims()->all(),
        );
    }
}
```

`src/Security/Claims.php`:
```php
<?php
namespace Ions\Security;
final class Claims
{
    public function __construct(public string $userId, public array $all = []) {}
}
```

> Requires `lcobucci/jwt:^5` and `lcobucci/clock`. Run `composer require lcobucci/jwt:^5.0`.

- [ ] **Step 4: Run tests to green.**
Run: `vendor/bin/pest tests/Unit/Security/JwtTest.php`
Expected: PASS (4 passing).

- [ ] **Step 5: Deprecate `AppKeys`, delegate to `Jwt`.**

In `src/Bundles/AppKeys.php`, keep the public methods but mark `@deprecated` and route through `Jwt` built from env (`APP_KEY`, `APP_NAME`). `createKey()` now generates a 32-byte random secret into `var/app.key` (chmod 600) instead of an RSA public key. `validateJWT()` returns `['success' => bool]` by catching `TokenException`. Add a `@deprecated Use Ions\Security\Jwt` docblock.

- [ ] **Step 6: Commit.**
```bash
git add src/Security tests/Unit/Security src/Bundles/AppKeys.php composer.json composer.lock
git commit -m "security: replace static, never-expiring JWT with user-bound, expiring HMAC tokens"
```

### Task 1.2: Close the file-upload RCE

**Files:**
- Create: `src/Security/UploadValidator.php`, `tests/Unit/Security/UploadValidatorTest.php`
- Modify: `src/Bundles/IonUpload.php`

- [ ] **Step 1: Failing test — reject dangerous extensions, normalize safe ones.**
```php
<?php
use Ions\Security\UploadValidator;

test('rejects php and other executable extensions', function () {
    $v = new UploadValidator(allowed: ['jpg','png','pdf']);
    expect($v->isAllowed('shell.php'))->toBeFalse()
        ->and($v->isAllowed('a.phtml'))->toBeFalse()
        ->and($v->isAllowed('a.PHP5'))->toBeFalse();
});

test('accepts allow-listed extensions case-insensitively', function () {
    $v = new UploadValidator(['jpg','png']);
    expect($v->isAllowed('photo.JPG'))->toBeTrue();
});

test('safeExtension strips path and lowercases', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->safeExtension('../../x.JPG'))->toBe('jpg');
});
```

- [ ] **Step 2: Run — confirm FAIL (class missing).**
Run: `vendor/bin/pest tests/Unit/Security/UploadValidatorTest.php`

- [ ] **Step 3: Implement `UploadValidator`.**
```php
<?php
namespace Ions\Security;

final class UploadValidator
{
    private const DENY = ['php','phtml','php3','php4','php5','php7','php8','phar','pht','htaccess','shtml','cgi','pl','asp','aspx','jsp','exe','sh','bat'];

    /** @param string[] $allowed */
    public function __construct(private array $allowed) {
        $this->allowed = array_map('strtolower', $allowed);
    }

    public function safeExtension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public function isAllowed(string $filename): bool
    {
        $ext = $this->safeExtension($filename);
        if ($ext === '' || in_array($ext, self::DENY, true)) {
            return false;
        }
        return in_array($ext, $this->allowed, true);
    }
}
```

- [ ] **Step 4: Run — green.**

- [ ] **Step 5: Wire into `IonUpload::store()`.**

In `src/Bundles/IonUpload.php`, before processing: build a validator from `config('app.uploads.allowed', ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx'])`, reject with `['error'=>1,'message'=>'extension not allowed']` when `!isAllowed($fileNameWithExt)`, and set `$handle->file_new_name_ext = $validator->safeExtension($fileNameWithExt)` (never the raw client extension). Add a feature test using a fake uploaded `.php` file asserting it is rejected.

- [ ] **Step 6: Commit.**
```bash
git add src/Security/UploadValidator.php tests/Unit/Security src/Bundles/IonUpload.php
git commit -m "security: enforce upload extension allow-list to prevent RCE via public/uploads"
```

### Task 1.3: Security headers + CORS + trusted host as middleware (interim, pre-pipeline)

Until the Phase 2 pipeline exists, add these as a small `Kernel::applySecurity(Response)` step so the fixes ship now and get refactored into middleware later.

**Files:**
- Create: `src/Security/SecurityHeaders.php`, `tests/Unit/Security/SecurityHeadersTest.php`
- Modify: `src/Foundation/Kernel.php` (call it before `send()`), `src/Foundation/Kernel.php:443` (trusted-host)

- [ ] **Step 1: Failing test — headers applied.**
```php
<?php
use Ions\Security\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

test('applies hardening headers', function () {
    $r = SecurityHeaders::apply(new Response('ok'));
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement.**
```php
<?php
namespace Ions\Security;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public static function apply(Response $r): Response
    {
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $r->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $r->headers->set('X-XSS-Protection', '0');
        if (!$r->headers->has('Content-Security-Policy')) {
            $r->headers->set('Content-Security-Policy', config('app.security.csp', "default-src 'self'"));
        }
        return $r;
    }
}
```

- [ ] **Step 4: Run — green. Wire into `Kernel::make()` before each `$response->send()`.**

- [ ] **Step 5: Replace the spoofable host check.**

In `Kernel::handleRouteRequest():443`, remove the `Host`-header equality throw. Replace with Symfony's trusted-hosts mechanism configured at boot:
```php
// in Kernel::boot(), after capturing request:
if ($hosts = config('app.trusted_hosts', [])) {
    Request::setTrustedHosts($hosts); // regex patterns
}
```
Document the break in `UPGRADE-2.0.md` (apps must set `app.trusted_hosts`). Add a test asserting a request with an untrusted host is rejected by Symfony when patterns are set, and allowed when empty.

- [ ] **Step 6: Commit.**
```bash
git add src/Security/SecurityHeaders.php tests/Unit/Security src/Foundation/Kernel.php
git commit -m "security: add hardening headers and replace spoofable host check with trusted hosts"
```

### Task 1.4: API input handling via Request abstraction + fix swallowed errors

**Files:**
- Modify: `src/Foundation/ApiController.php`, `src/Foundation/Kernel.php`

- [ ] **Step 1:** Replace raw `$_SERVER/$_POST/$_FILES/$_GET` access in `ApiController::renderRequest()`/`isAuthorized()` with `$this->request->...` (Symfony `Request`: `->getContent()`, `->request->all()`, `->files->all()`, `->query->all()`, `->headers->get('Authorization')`). Move JWT auth out of the constructor — see Phase 2 `AuthMiddleware`; for now call `Security\Jwt` via a private method that throws on failure. Add a feature test posting JSON to a fixture API route asserting parsed inputs.

- [ ] **Step 2:** Replace empty `catch (Throwable) {}` blocks in `Kernel::boot()` and `captureConfig()` with logging via `Bundles\Logs` + re-throw in debug mode, so boot failures are diagnosable. Test: boot with a broken config dir asserts a logged error.

- [ ] **Step 3: Commit.**
```bash
git commit -am "security: parse API input through Request abstraction; stop swallowing boot errors"
```

**Phase 1 acceptance:** JWTs expire and bind users; uploads reject executables; hardening headers present; host check no longer spoofable; no raw superglobals in `ApiController`. All Phase 1 commits cherry-picked to `1.x` and tagged `v1.x.y`.

---

## Phase 2 — DI container, service providers, middleware pipeline

> **Expanded into its own detailed plan:** `docs/superpowers/plans/2026-06-09-phase2-container-middleware.md` (6 sequenced sub-phases, TDD). Spec summary below.

**Goal:** Introduce `Ions\Container\Container` (PSR-11, wrapping `Illuminate\Container`), a `ServiceProvider` base, and a PSR-15-style middleware pipeline. Convert `Kernel` from "static god object" to "boot the container, run providers, dispatch through pipeline."

**Tasks:**
1. `Container` wrapping `Illuminate\Container\Container` implementing `Psr\Container\ContainerInterface`; typed `get(string $id)`. Test: bind + resolve + singleton semantics.
2. `ServiceProvider` abstract (`register(Container)`, `boot(Container)`). Provider list from `config('app.providers', [...defaults])`.
3. Move existing wiring into providers: `ConfigProvider` (config load), `DatabaseProvider` (port `RegisterDB`), `FilesystemProvider`, `ViewProvider` (twig/smarty), `MailProvider`, `AuthProvider`, `RoutingProvider`.
4. `Pipeline` + `MiddlewareInterface` (adopt `psr/http-server-middleware` or a thin Symfony-Request-based equivalent — decide in the expansion plan; Symfony Request/Response is already the currency, so a lightweight `handle(Request, next): Response` interface avoids a PSR-7 bridge).
5. Port Phase-1 security into middleware: `TrustedHostMiddleware`, `SecurityHeadersMiddleware`, `CorsMiddleware`, `CsrfMiddleware`, `AuthMiddleware`. Route groups (`web`/`api`) select middleware stacks via `config('app.middleware')`. The `AuthMiddleware` reuses `Ions\Http\RequestInput` (built in Task 1.4) for body parsing and `Ions\Security\Jwt` for verification; lift the auth logic out of `ApiController::__construct` (the `// TODO(Phase 2)` marker there).
   - **Tracked from Task 1.4:** add the auth integration tests that were deferred (currently only `RequestInput` and `Jwt` are unit-tested, not the gate itself): missing header → 401; non-Bearer scheme → 401; short/empty `APP_KEY` → 401 (NOT 500); expired token → 401; valid token → `auth_user_id` attribute set. These become clean once auth is in middleware rather than the constructor.
6. `Kernel::handle(Request): Response` returns instead of `send()`-and-`exit()`; a thin `Kernel::run()` sends. This makes the kernel testable end-to-end.
7. **BC shims:** keep `Kernel::session()/request()/response()/app()/config()` as static accessors that read from the container, so existing controllers/helpers keep working. Mark `Singleton` usages `@deprecated`.

**Acceptance:** A fixture request flows Request → pipeline → controller → Response entirely in a test, with auth enforced by middleware, not constructors.

Config keys (`app.providers`, `app.middleware`, `app.cors`, `app.jwt.ttl`, `app.trusted_hosts`) documented in `docs/phase2-config.md`.

---

## Phase 3 — HTTP / routing / controllers modernization

> **Expand into its own plan before executing.**

**Tasks:**
1. Consolidate routing: keep `Bundles\Route` (fluent) + attribute routing; **remove `MRoute`** (document migration). Add typed route registration and `Route::middleware([...])` per route/group.
2. Make `ControllerDispatcher` (middleware terminal) own the lifecycle hooks (`_initState`→`_loadInit`→`_loadedState`→action→`_endState`) currently inlined in `Kernel::instanceTheController()`; resolve controllers **through the container** (constructor injection) instead of `new $controller()`.
3. Typed responses: introduce `Ions\Http\JsonResponse` helpers and a `Responsable` interface so controllers can `return $response` rather than `echo`/`exit`. Keep `display()`/`render()` helpers as shims.
   - **Tracked from Task 1.4:** `ApiController::display()` (raw `echo`+`exit`) bypasses `SecurityHeaders` (only `returnStructure()` applies them). Once controllers return `Response` objects routed through the pipeline, the headers apply uniformly and this gap closes.
4. Replace `exit()`/`die()` control flow in `Kernel`/`ApiController` with thrown HTTP exceptions handled centrally (already have `abort()`), enabling testability.
5. Centralized exception handling: one handler that renders Ignition (debug) / Whoops-JSON (api) / templated error (prod), replacing the duplicated `errorDebug`/`errorDebugApi`.
6. **Tracked debt:** Smarty pinned at ^4 (Smarty 5 is a namespaced rewrite `Smarty\Smarty`). Evaluate migrating to Smarty 5 or dropping Smarty in favour of Twig-only when modernizing the view layer.
7. **Tracked from Phase 1 final review:** (a) `#[NoReturn]` uses `JetBrains\PhpStorm\NoReturn`, an IDE-only stub not declared as a dependency (baselined in phpstan). When reworking controllers/Kernel here, replace `#[NoReturn]` with PHP 8.1 `never` return types (real, enforced, dependency-free) and drop the baseline entry. (b) `IonDisk::download()` has **inverted read/write semantics** (it uploads the local file to the cloud path instead of downloading) — pre-existing bug, untouched by Phase 1; fix with a regression test when reworking the storage layer.

**Acceptance:** Controllers are container-resolved, return responses, and are unit-testable without HTTP; error rendering is single-sourced.

---

## Phase 4 — Database / ORM consolidation + Eloquent upgrade

> **Expand into its own plan before executing.**

**Tasks:**
1. Upgrade Illuminate `9.52.4` → `^11.0` (Rector + `illuminate/*` bumps). Run Rector's Laravel sets where applicable; fix breaking changes (e.g. `Capsule`/Eloquent API deltas). Verify against fixture SQLite + a MySQL CI service.
2. Decide RedBean's fate: **recommend removing** `redbean` engine (it duplicates Eloquent, ships untyped global `R::`, and adds a heavy dependency). If kept, isolate it behind an interface and a `suggest`. Document either way.
3. Consolidate the two query builders: keep `Builders\QueryBuilder` (request-driven, with `Invalid*Query` exceptions) as the public API; fold `Bundles\QueryBuilder` operators into it or delete. Harden against column-name injection: allow-list sortable/filterable fields (the `BuilderFields`/`BuilderSort`/`BuilderFilters` traits) and bind all values.
4. Formalize migrations/seeders commands against upgraded Illuminate console; add tests for `migrate`/`rollback`/`schema`.
5. Wrap `DatabaseProvider` with lazy connections + query log gated by `APP_DEBUG`.

**Acceptance:** Eloquent 11 green on SQLite + MySQL CI; one query-builder API; filter/sort columns allow-listed (injection test passes).

---

## Phase 5 — Auth subsystem rebuild

> **Expand into its own plan before executing.**

**Tasks:**
1. Define an `Authenticatable` contract + `UserProvider` interface so auth isn't hardwired to Sentinel.
2. Keep Cartalyst Sentinel as the default `UserProvider` implementation behind the interface (`Auth\Guard\*` become adapters), but allow apps to swap it.
3. `AuthMiddleware` (api): extract Bearer token → `Jwt::verify` → resolve user via `UserProvider` → attach to request attributes. Web: session guard.
4. Add **refresh tokens** + token revocation (jti deny-list in cache) and login throttling/rate-limiting middleware.
5. CSRF: keep Symfony CSRF helpers (`csrfToken`/`ionToken`/`csrfCheck`) but enforce via `CsrfMiddleware` on state-changing web routes by default.

**Tracked deferrals carried over from Task 1.1 (JWT) — address here:**
- **D5-A — Clock-skew leeway:** `Ions\Security\Jwt` uses `StrictValidAt` with a system clock and `nbf = iat = now`, no leeway → a verifier whose clock lags the issuer rejects fresh tokens under NTP drift. Add a `int $clockLeewaySeconds` constructor param wired into `StrictValidAt` (e.g. `new StrictValidAt($clock, new DateInterval("PT{$leeway}S"))`), surfaced via `config('app.jwt.leeway')`. Default 0 preserves single-node behavior.
- **D5-B — `AppKeys::createJWT()` no-arg path is not user-bound:** it defaults `sub` to the constant `config('app.app_id')`. `AuthMiddleware` and any new caller MUST resolve and validate `Claims->userId`, never trust `['success']` alone. When `AppKeys` is finally removed, drop the constant-subject fallback entirely. (KeyCommand was already updated to pass an explicit `'system'` subject in Task 1.1.) Consider emitting `E_USER_DEPRECATED` from the no-arg path so silent callers surface.
- **D5-C — Upload destination-path traversal (defense-in-depth):** `IonUpload`/`IonDisk` now validate the file *extension*, but the destination `$path`/`$userProvidedPath` passed to `IonDisk::putFile()` / `handleCloudUpload()` is not normalized against `../` traversal. Verify Flysystem v3's actual traversal handling and, if it doesn't reject `..`, normalize/whitelist the destination path. (Currently low risk because destinations are app-controlled, not client-supplied — but harden when building the storage layer.)

**Acceptance:** Pluggable user provider; JWT auth with refresh + revocation; rate-limited login; CSRF enforced by middleware with tests; D5-A leeway configurable and tested; D5-B userId always validated in the auth path.

---

## Phase 6 — DX, docs, generators, release

> **Expand into its own plan before executing.**

**Tasks:**
1. Raise PHPStan to level 8 on `src/Security`, `src/Container`, `src/Http`; burn down baseline incrementally; add `declare(strict_types=1)` to all new/touched files.
2. Modernize generators (`src/commands/*`): update stubs to emit container-aware controllers, typed responses, middleware; add `make:middleware`, `make:provider`.
3. Write `UPGRADE-2.0.md` (every documented break: trusted hosts, JWT format/`APP_KEY`, upload allow-list, `MRoute` removal, RedBean removal, container resolution, return-response controllers) and `CHANGELOG.md`.
4. Author real documentation (README + `/docs`): quick start, lifecycle, routing, middleware, auth, config reference.
5. **Image processing replacement (tracked from Task 1.2):** dropping `verot` removed server-side image resize/watermark that `IonDisk::put()` exposed via `$options`. If still needed, integrate `intervention/image` (^3) behind a small `Ions\Media\Image` helper and re-wire optional resize as an explicit post-`store` step (not silent). Otherwise document the removal as permanent.
6. Tag `v2.0.0`. Keep `1.x` branch for security backports.

**Acceptance:** `composer qa` green at high PHPStan level on core packages; upgrade guide complete; v2.0.0 tagged.

---

## v2 upgrade guide outline (`UPGRADE-2.0.md`)

Breaking changes apps must address, each with before/after:
1. **JWT:** set `APP_KEY` (≥32 bytes); re-issue tokens (old tokens invalid; now expire). `AppKeys` deprecated → `Ions\Security\Jwt`.
2. **Trusted hosts:** set `app.trusted_hosts` (regex list, patterns **without** delimiters — Symfony wraps each as `{pattern}i`; e.g. `['^(.+\.)?example\.com$']`, NOT `['{^...$}i']`) — the old spoofable `Host`==`APP_URL` check is gone. Empty/unset = no host restriction.
   - **Security headers / CSP:** every response now carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-XSS-Protection`, and a `Content-Security-Policy` that **defaults to `default-src 'self'`**. This will break apps using CDN assets or inline scripts — set `app.security.csp` to an appropriate policy before going live. (The four non-CSP headers are enforced unconditionally; CSP only applies if the response doesn't already set one.) Note: `ApiController` auth-error responses get these headers wired in Task 1.4.
3. **Uploads:** configure `app.uploads.allowed`; executable extensions now rejected (`IonUpload::store`, `IonDisk::put`/`putFile`). The `verot/class.upload.php` dependency was **removed** (it had an unpatched RCE advisory and no safe stable release). **Image resize/watermark via `$options`** on `IonDisk::put()`/`handleLocalUpload()` no longer works (it was a verot feature) and is now **silently ignored** — callers needing image processing must post-process explicitly (planned replacement: `intervention/image`, tracked in Phase 6). Stored filenames are now always `Str::random(15).<safe-ext>`, or a `Str::slug`-sanitized original stem when `$withOriginal=true`.
4. **Routing:** `MRoute` removed → use `Route`.
5. **Database:** Eloquent 9→11 deltas; RedBean removed (or now opt-in `suggest`).
6. **Controllers:** resolved via container; prefer returning a `Response`. `echo`/`exit` patterns still work via shims but are deprecated.
7. **Dependencies:** Twig/Smarty/Ignition/Whoops now declared by core; remove duplicate requires from host `composer.json` if desired.

---

## Risks & mitigations

- **Illuminate 9→11 jump is the biggest compatibility risk.** Mitigate: do it in Phase 4 behind the test net from Phases 0–3, use Rector Laravel sets, run MySQL in CI.
- **Breaking existing apps.** Mitigate: BC shims for `Kernel` static accessors and helpers; everything documented in `UPGRADE-2.0.md`; `1.x` security line keeps prod safe during migration.
- **Scope creep across four subsystems.** Mitigate: each phase ships independently and is expanded into its own plan only when reached; `main`/`1.x` always releasable.
- **Undeclared deps may not exist under assumed names.** Mitigate: verify each on Packagist in Task 0.1 before locking.

---

## Self-review notes

- **Spec coverage:** Security & auth → Phases 1 + 5; DI & middleware → Phase 2; DB & ORM → Phase 4; DX & tooling → Phases 0 + 6. Breaking-changes-with-upgrade-guide → §upgrade guide + Phase 6. Plan-first delivery → this document. All four chosen priorities covered.
- **Placeholders:** Phases 0–1 contain full code/commands. Phases 2–6 are intentionally task-level and flagged "expand into own plan" — this is the documented decomposition strategy for independent subsystems, not a placeholder gap.
- **Type consistency:** `Jwt::issue(string $userId)`/`verify(): Claims`, `Claims->userId`, `UploadValidator::isAllowed()/safeExtension()`, `SecurityHeaders::apply()`, `Path::setBasePath()/base()` are used consistently across tasks.
