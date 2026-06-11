<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Bundles\Path;
use Throwable;

/**
 * `ions doctor` — host-application diagnostics.
 *
 * Runs a fixed list of health checks against the booted host app (new
 * diagnostics are added by editing checks() below)
 * and returns structured results that the DoctorCommand renders as a console
 * table or JSON. Pure logic lives here so it is testable without console
 * plumbing.
 *
 * Each result is an array {id, label, status, message}. Severity contract:
 *
 *  - FAIL — critical misconfig, the app is (or will be) broken: missing or
 *    short APP_KEY, unwritable var/ subdirectory, a configured database that
 *    cannot be reached, a missing required PHP extension. `ions doctor`
 *    exits non-zero when any FAIL is present.
 *  - WARN — risky but running: APP_DEBUG on, wildcard CORS, explicitly
 *    insecure session cookies, no trusted hosts, CSRF disabled. Doctor
 *    cannot know whether this host *is* production, so these never fail
 *    the run — read them with that context in mind.
 *  - INFO — state worth knowing: production caches absent (run `optimize`),
 *    which provider-discovery mode is active.
 *  - SKIP — not applicable to this host (e.g. no database engine configured).
 */
final class Doctor extends Singleton
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    /**
     * Minimum APP_KEY length in bytes — mirrors Kernel::buildJwt(), which
     * refuses to sign with anything shorter.
     */
    private const MIN_APP_KEY_LENGTH = 32;

    /**
     * PHP extensions the framework itself requires (composer.json ext-*).
     *
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = ['openssl', 'sodium', 'zip'];

    /**
     * Run every check and return the structured results.
     *
     * @return list<array{id: string, label: string, status: string, message: string}>
     */
    public static function run(): array
    {
        $results = [];

        foreach (self::checks() as $check) {
            // Structural guard: a single throwing check must degrade to a FAIL
            // row, never kill the whole diagnosis.
            try {
                foreach ($check() as $result) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $results[] = self::result(
                    'check_error',
                    'Doctor check',
                    self::FAIL,
                    sprintf('A diagnostic check threw %s: %s', $e::class, $e->getMessage())
                );
            }
        }

        return $results;
    }

    /**
     * Tally results by status.
     *
     * @param list<array{id: string, label: string, status: string, message: string}> $results
     * @return array{ok: int, info: int, warn: int, fail: int, skip: int}
     */
    public static function summary(array $results): array
    {
        $summary = ['ok' => 0, 'info' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];

        foreach ($results as $result) {
            if (array_key_exists($result['status'], $summary)) {
                $summary[$result['status']]++;
            }
        }

        return $summary;
    }

    /**
     * Whether any result is a critical failure.
     *
     * @param list<array{id: string, label: string, status: string, message: string}> $results
     */
    public static function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registered checks, in display order. Each callable yields one or
     * more results — add new diagnostics here.
     *
     * @return list<callable(): list<array{id: string, label: string, status: string, message: string}>>
     */
    private static function checks(): array
    {
        return [
            static fn (): array => [self::checkEnvLoaded()],
            static fn (): array => [self::checkAppKey()],
            static fn (): array => [self::checkAppUrl()],
            static fn (): array => self::checkDualAppDirs(),
            static fn (): array => self::checkVarWritable(),
            static fn (): array => [self::checkRouteCache()],
            static fn (): array => [self::checkConfigCache()],
            static fn (): array => [self::checkProvidersCache()],
            static fn (): array => [self::checkDatabase()],
            static fn (): array => [self::checkPhpExtensions()],
            static fn (): array => [self::checkCsrf()],
            static fn (): array => [self::checkTrustedHosts()],
            static fn (): array => [self::checkTrustedProxies()],
            static fn (): array => [self::checkSessionCookies()],
            static fn (): array => [self::checkCors()],
            static fn (): array => [self::checkDebug()],
            static fn (): array => [self::checkDiscovery()],
        ];
    }

    /**
     * Build one result row.
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function result(string $id, string $label, string $status, string $message): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'message' => $message];
    }

    // ------------------------------------------------------------ env / key

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkEnvLoaded(): array
    {
        $file = Path::root('.env');

        if (is_file($file)) {
            return self::result('env_loaded', 'Environment', self::OK, '.env found and loaded from the host root.');
        }

        return self::result(
            'env_loaded',
            'Environment',
            self::WARN,
            'No .env file at the host root — fine if real environment variables are provided (container/CI), otherwise create one.'
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkAppKey(): array
    {
        $key = (string) env('APP_KEY', '');

        if ($key === '') {
            return self::result(
                'app_key',
                'APP_KEY',
                self::FAIL,
                'APP_KEY is missing — sessions, JWT and encryption cannot sign. Run `ions make:key` and set it in .env (>= 32 chars).'
            );
        }

        if (strlen($key) < self::MIN_APP_KEY_LENGTH) {
            return self::result(
                'app_key',
                'APP_KEY',
                self::FAIL,
                sprintf(
                    'APP_KEY is %d bytes — at least %d are required (JWT signing is disabled below that). Run `ions make:key`.',
                    strlen($key),
                    self::MIN_APP_KEY_LENGTH
                )
            );
        }

        return self::result('app_key', 'APP_KEY', self::OK, sprintf('APP_KEY present (%d bytes).', strlen($key)));
    }

    /**
     * config('app.app_url') is the canonical absolute base for generated URLs:
     * signedRoute()/url() links and the appUrl Twig global all come out
     * scheme-less or empty without it.
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkAppUrl(): array
    {
        $url = trim((string) config('app.app_url', ''));

        if ($url === '') {
            return self::result(
                'app_url',
                'app.app_url',
                self::WARN,
                'app.app_url is empty — signedRoute()/url() links and the appUrl view global will be broken. Set APP_URL in .env (the skeleton config reads it) or app_url in config/app.php.'
            );
        }

        return self::result('app_url', 'app.app_url', self::OK, sprintf('app.app_url set (%s).', $url));
    }

    // ---------------------------------------------------------- host layout

    /**
     * Since 4.2, Path resolves {root}/app before {root}/src — a host carrying
     * BOTH directories silently ignores src/. Conditional check: yields no
     * row on single-layout hosts (nothing to say there).
     *
     * @return list<array{id: string, label: string, status: string, message: string}>
     */
    private static function checkDualAppDirs(): array
    {
        if (!is_dir(Path::root('app')) || !is_dir(Path::root('src'))) {
            return [];
        }

        return [self::result(
            'dual_app_dirs',
            'App layout',
            self::WARN,
            'Both app/ and src/ exist at the host root — app/ wins since 4.2, so src/ is ignored by Path resolution; consolidate your application code into app/ and remove the unused directory.'
        )];
    }

    // ---------------------------------------------------------- var/ layout

    /** @return list<array{id: string, label: string, status: string, message: string}> */
    private static function checkVarWritable(): array
    {
        $dirs = [
            'writable_var' => ['var', rtrim(Path::var(''), DIRECTORY_SEPARATOR)],
            'writable_cache' => ['var/cache', rtrim(Path::cache(''), DIRECTORY_SEPARATOR)],
            'writable_logs' => ['var/logs', rtrim(Path::logs(''), DIRECTORY_SEPARATOR)],
            'writable_templates' => ['var/templates', rtrim(Path::templates(''), DIRECTORY_SEPARATOR)],
        ];

        $results = [];
        foreach ($dirs as $id => [$name, $dir]) {
            $label = $name . ' writable';

            if (is_dir($dir)) {
                $results[] = is_writable($dir)
                    ? self::result($id, $label, self::OK, sprintf('%s/ is writable.', $name))
                    : self::result($id, $label, self::FAIL, sprintf('%s/ exists but is not writable — fix permissions (e.g. `chmod -R u+rwX %s`).', $name, $name));

                continue;
            }

            $results[] = self::creatable($dir)
                ? self::result($id, $label, self::WARN, sprintf('%s/ is missing but can be created — `mkdir -p %s` (the framework also creates it on demand).', $name, $name))
                : self::result($id, $label, self::FAIL, sprintf('%s/ is missing and cannot be created (parent not writable) — create it and fix permissions.', $name));
        }

        return $results;
    }

    /**
     * Whether a missing directory could be created: its nearest existing
     * ancestor is writable.
     */
    private static function creatable(string $dir): bool
    {
        $parent = dirname($dir);
        while (!is_dir($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }

        return is_writable($parent);
    }

    // -------------------------------------------------------------- caches

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkRouteCache(): array
    {
        $web = Path::cache('routes' . DIRECTORY_SEPARATOR . 'web.php');
        $api = Path::cache('routes' . DIRECTORY_SEPARATOR . 'api.php');

        if (is_file($web) && is_file($api)) {
            return self::result('route_cache', 'Route cache', self::OK, 'Routes are cached (var/cache/routes/).');
        }

        return self::result(
            'route_cache',
            'Route cache',
            self::INFO,
            'Routes are not cached — run `ions optimize` (or `route:cache`) on deploy for faster boot.'
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkConfigCache(): array
    {
        if (is_file(Path::cache('config.php'))) {
            return self::result('config_cache', 'Config cache', self::OK, 'Config is cached (var/cache/config.php).');
        }

        return self::result(
            'config_cache',
            'Config cache',
            self::INFO,
            'Config is not cached — run `ions optimize` (or `config:cache`) on deploy for faster boot.'
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkProvidersCache(): array
    {
        if (is_file(Path::cache('providers.php'))) {
            return self::result('providers_cache', 'Provider cache', self::OK, 'Discovered providers are cached (var/cache/providers.php).');
        }

        return self::result(
            'providers_cache',
            'Provider cache',
            self::INFO,
            'Provider discovery is not cached — run `ions optimize` (or `discover:cache`) on deploy.'
        );
    }

    // ------------------------------------------------------------- database

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkDatabase(): array
    {
        $engines = (array) config('app.database_engine', []);

        if (!in_array('db', $engines, true)) {
            return self::result('db', 'Database', self::SKIP, "No database engine configured (config('app.database_engine')) — skipped.");
        }

        try {
            $app = Kernel::app();

            if (!$app->has('db')) {
                return self::result(
                    'db',
                    'Database',
                    self::FAIL,
                    "The 'db' engine is configured but no 'db' binding exists — is the DatabaseProvider registered?"
                );
            }

            /** @var \Illuminate\Database\Capsule\Manager $capsule */
            $capsule = $app->get('db');
            $capsule->getConnection()->select('select 1');
        } catch (Throwable $e) {
            return self::result(
                'db',
                'Database',
                self::FAIL,
                sprintf('Database is configured but unreachable: %s — check config/database.php and the server.', $e->getMessage())
            );
        }

        return self::result('db', 'Database', self::OK, 'Database connection OK (select 1 succeeded).');
    }

    // --------------------------------------------------------- environment

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkPhpExtensions(): array
    {
        $required = self::REQUIRED_EXTENSIONS;

        $engines = (array) config('app.database_engine', []);
        if (in_array('db', $engines, true)) {
            $required[] = 'pdo';
        }

        $missing = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));

        if ($missing !== []) {
            return self::result(
                'php_extensions',
                'PHP extensions',
                self::FAIL,
                sprintf('Missing required PHP extension(s): %s — install/enable them in php.ini.', implode(', ', $missing))
            );
        }

        return self::result(
            'php_extensions',
            'PHP extensions',
            self::OK,
            sprintf('All required extensions loaded (%s).', implode(', ', $required))
        );
    }

    // ----------------------------------------------------- security posture

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkCsrf(): array
    {
        if (config('app.csrf.enabled', true)) {
            return self::result('csrf', 'CSRF protection', self::OK, 'CSRF protection is enabled.');
        }

        return self::result(
            'csrf',
            'CSRF protection',
            self::WARN,
            "CSRF protection is disabled (config('app.csrf.enabled') = false) — re-enable it unless every state-changing route is token-authenticated."
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkTrustedHosts(): array
    {
        $hosts = (array) config('app.trusted_hosts', []);

        if ($hosts !== []) {
            return self::result('trusted_hosts', 'Trusted hosts', self::OK, sprintf('Trusted hosts configured (%d pattern(s)).', count($hosts)));
        }

        return self::result(
            'trusted_hosts',
            'Trusted hosts',
            self::WARN,
            "No trusted hosts configured — Host-header validation relies on the APP_URL check alone. Set config('app.trusted_hosts')."
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkTrustedProxies(): array
    {
        $proxies = (array) config('app.trusted_proxies', []);

        if ($proxies !== []) {
            return self::result('trusted_proxies', 'Trusted proxies', self::OK, sprintf('Trusted proxies configured (%d entr%s).', count($proxies), count($proxies) === 1 ? 'y' : 'ies'));
        }

        // INFO, not WARN: serving PHP directly (no proxy in front) is a
        // perfectly healthy topology that needs no proxy trust.
        return self::result(
            'trusted_proxies',
            'Trusted proxies',
            self::INFO,
            "No trusted proxies configured — fine when serving directly. Behind a TLS-terminating proxy/load balancer set config('app.trusted_proxies') so isSecure(), client IPs, HSTS and cookie_secure => 'auto' honour X-Forwarded-* headers."
        );
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkSessionCookies(): array
    {
        // Cookies are secure by default since 4.1 — only flag EXPLICIT
        // insecure overrides in config/session.php.
        $insecure = [];
        if (config('session.cookie_secure') === false) {
            $insecure[] = 'cookie_secure=false';
        }
        if (config('session.cookie_httponly') === false) {
            $insecure[] = 'cookie_httponly=false';
        }

        if ($insecure !== []) {
            return self::result(
                'session_cookies',
                'Session cookies',
                self::WARN,
                sprintf(
                    'Explicit insecure session cookie override(s): %s — remove them (secure defaults apply) unless this host is HTTP-only by design.',
                    implode(', ', $insecure)
                )
            );
        }

        return self::result('session_cookies', 'Session cookies', self::OK, 'Session cookie flags use secure defaults (no insecure overrides).');
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkCors(): array
    {
        $origins = (array) config('app.cors.origins', []);

        if (in_array('*', $origins, true)) {
            $note = config('app.cors.credentials') === true
                ? ' Note: credentials are never combined with the wildcard origin (Fetch spec), so cookies will not be sent.'
                : '';

            return self::result(
                'cors',
                'CORS',
                self::WARN,
                "Wildcard CORS origin (['*']) — every site may call this app cross-origin. List explicit origins in config('app.cors.origins')." . $note
            );
        }

        if ($origins === []) {
            return self::result('cors', 'CORS', self::OK, 'CORS is deny-by-default (no origins configured).');
        }

        return self::result('cors', 'CORS', self::OK, sprintf('CORS restricted to %d explicit origin(s).', count($origins)));
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkDebug(): array
    {
        if (env('APP_DEBUG', false)) {
            return self::result(
                'debug',
                'Debug mode',
                self::WARN,
                'APP_DEBUG is enabled — error pages expose internals and caches are bypassed. Disable it in production (doctor cannot tell whether this host is production).'
            );
        }

        return self::result('debug', 'Debug mode', self::OK, 'APP_DEBUG is off.');
    }

    // ------------------------------------------------------------ discovery

    /** @return array{id: string, label: string, status: string, message: string} */
    private static function checkDiscovery(): array
    {
        // Mirrors Kernel::bootProviders() exactly: ANY array set at
        // app.providers (including []) is explicit mode, and any falsy
        // app.discovery value disables discovery.
        $explicit = config('app.providers');
        if (is_array($explicit)) {
            if ($explicit === []) {
                return self::result('discovery', 'Provider discovery', self::WARN, "config('app.providers') is an empty array — explicit mode with ZERO providers registered (no framework bindings at all). List the providers, or remove the key to enable discovery.");
            }

            return self::result('discovery', 'Provider discovery', self::INFO, "Explicit config('app.providers') list — discovery is bypassed (zero scans).");
        }

        if (!config('app.discovery', true)) {
            return self::result('discovery', 'Provider discovery', self::INFO, "Discovery disabled (config('app.discovery') is falsy) — framework default providers only.");
        }

        if (is_file(Path::cache('providers.php'))) {
            $suffix = env('APP_DEBUG', false) ? ' (ignored while APP_DEBUG is on — debug always discovers live)' : '';

            return self::result('discovery', 'Provider discovery', self::INFO, 'Cached provider list in use (var/cache/providers.php)' . $suffix . '.');
        }

        return self::result('discovery', 'Provider discovery', self::INFO, 'Live discovery at boot — run `ions optimize` in production to freeze the provider list.');
    }
}
