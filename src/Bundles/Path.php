<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use RuntimeException;

class Path extends Singleton
{
    /**
     * Centralized path-containment guard for the uploads/host-root path
     * builders (files()/filesRoot()). Every caller-controllable segment that
     * is concatenated onto a trusted root MUST pass through this gate first, so
     * downstream consumers (read/write/move/delete sinks) are safe by
     * construction.
     *
     * Fail-closed contract: an argument containing a `..` traversal segment or
     * an absolute prefix (leading `/`, a `\\` UNC prefix, or a `X:\` Windows
     * drive) throws a RuntimeException. Legitimate nested RELATIVE subpaths —
     * e.g. `avatars/2024/x.jpg` — pass through untouched so existing structure
     * keeps working. Empty values are allowed (they resolve to the root).
     *
     * @param string ...$segments Each caller-controllable path fragment.
     */
    protected static function assertContained(string ...$segments): void
    {
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_contains($segment, "\0")) {
                throw new RuntimeException("Refusing a path containing a null byte: $segment");
            }

            // Absolute prefixes escape the trusted root. A leading slash covers
            // both POSIX absolute and UNC `\\host\share` (normalized to `//`);
            // the regex covers a Windows drive letter `X:\` / `X:/`.
            $normalized = str_replace('\\', '/', $segment);
            if (str_starts_with($normalized, '/')
                || preg_match('#^[a-zA-Z]:/#', $normalized) === 1) {
                throw new RuntimeException("Refusing an absolute path argument: $segment");
            }

            // Any `..` traversal segment (anywhere in the path) is rejected.
            if (in_array('..', explode('/', $normalized), true)) {
                throw new RuntimeException("Refusing a path containing '..': $segment");
            }
        }
    }

    protected static string $environmentPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;

    protected static ?string $basePath = null;

    /**
     * Override the host-app base path (used by tests / CLI tools).
     * Call resetBasePath() to restore the default 5-levels-up resolution.
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Reset the injected base path so the default 5-levels-up fallback is used.
     */
    public static function resetBasePath(): void
    {
        self::$basePath = null;
    }

    /**
     * Return the resolved base path of the host application.
     * When setBasePath() has been called the injected value is used;
     * otherwise falls back to the original realpath(5-up) resolution.
     */
    protected static function base(): string
    {
        if (self::$basePath !== null) {
            return rtrim(self::$basePath, DIRECTORY_SEPARATOR);
        }

        return realpath(self::$environmentPath);
    }

    /**
     * config folder in app folder
     *
     * @param string $file
     * @return string
     */
    public static function config(string $file = ''): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * The host application code directory. Since 4.2 the `app/` convention
     * wins: `{root}/app` is used whenever it exists (and for fresh hosts with
     * neither directory); `{root}/src` is the preserved fallback for hosts
     * still on the legacy layout.
     */
    private static function appDir(): string
    {
        $base = self::base();

        if (!is_dir($base . DIRECTORY_SEPARATOR . 'app') && is_dir($base . DIRECTORY_SEPARATOR . 'src')) {
            // Legacy layout fallback — only src/ exists.
            return $base . DIRECTORY_SEPARATOR . 'src';
        }

        // The 'app' folder convention (also the default for fresh hosts).
        return $base . DIRECTORY_SEPARATOR . 'app';
    }

    /**
     * Host application code folder: app/ first, src/ fallback (see appDir()).
     *
     * @param string $file
     * @return string
     */
    public static function src(string $file): string
    {
        return self::appDir() . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * views folder
     *
     * @param string $file
     * @return string
     */
    public static function views(string $file): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * API controllers folder ({app|src}/Http/Api): app/ first, src/ fallback.
     *
     * @param string $file
     * @return string
     */
    public static function api(string $file = ''): string
    {
        return self::appDir() . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * core folder
     *
     * @param string $file
     * @return string
     */
    public static function core(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * var folder
     *
     * @param string $file
     * @return string
     */
    public static function var(string $file): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Routes folder
     *
     * @param string $file
     * @return string
     */
    public static function route(string $file = ''): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * root is base of project
     *
     * @param string $file
     * @param bool $url
     * @return string
     */
    public static function root(string $file, bool $url = false): string
    {
        if ($url) {
            return env('APP_URL') . '/' . $file;
        }
        return self::base() . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * root folder is base of project
     *
     * @param string $file
     * @param string|null $folder_name
     * @return string
     */
    public static function rootFolder(string $file, ?string $folder_name = null): string
    {
        if ($folder_name) {
            return env('APP_URL') . '/' . $folder_name . '/' . $file;
        }
        return env('APP_URL') . '/' . $file;
    }

    /**
     * cache folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function cache(string $file): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * assets folder in web folder
     *
     * @param string $file
     * @param string $folder_name
     * @return string
     */
    public static function assets(string $file, string $folder_name = 'default'): string
    {
        return env('APP_URL') . '/' . 'views' . '/' . $folder_name . '/' . $file;
    }

    /**
     * public folder
     *
     * @param string $file
     * @return string
     */
    public static function public(string $file = '', bool $url = false): string
    {
        if ($url) {
            return env('APP_URL') . '/' . 'public' . '/' . $file;
        }
        return self::base() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * lang folder
     *
     * @param string $file
     * @return string
     */
    public static function locale(string $file = ''): string
    {
        return self::base() . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * logs folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function logs(string $file): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * files folder in var folder
     *
     * @param string $file
     * @param bool $url
     * @return string
     */
    public static function files(string $file, bool $url = false, string $mainFolder = ''): string
    {
        // FINDING 6: containment at the root cause — reject traversal/absolute
        // arguments before any concatenation, fail-closed for every caller.
        self::assertContained($file, $mainFolder);

        if (env('FILESYSTEM_DISK', 'local') === 's3' && env('AWS_BASE_PATH')) {
            $mainFolder = env('AWS_BASE_PATH');
            $file = $mainFolder . '/' . $file;
        }
        if (env('FILESYSTEM_DISK', 'local') === 's3') {
            if ($url) {
                // get cdn url if available
                $options = [];
                if (env('AWS_CDN_URL')) {
                    $options['cdn_base_url'] = env('AWS_CDN_URL');
                }
                return IonDisk::getUrl($file, collect($options));
            }
            return $file;
        }
        if ($url) {
            return env('APP_URL') . '/' . 'public' . '/' . 'uploads' . '/' . $file;
        }
        return self::base() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file;
    }

    public static function filesRoot(string $file, bool $url = false, string $mainFolder = '', string $bucket = ''): string
    {
        // FINDING 6: containment at the root cause (see files()).
        self::assertContained($file, $mainFolder, $bucket);

        if (env('FILESYSTEM_DISK', 'local') === 's3' && $mainFolder) {
            $file = $mainFolder . '/' . $file;
        }
        if (env('FILESYSTEM_DISK', 'local') === 's3') {
            if ($url) {
                return IonDisk::getSignedUrl($file);
            }
            return IonDisk::getSignedUrl($file);
        }
        if ($url) {
            return env('APP_URL') . '/' . $file;
        }
        return self::base() . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Maps the legacy `{app|src}/Database` subfolder names (the exact names
     * the framework commands pass into database()) onto the lowercase
     * standard directories of the host-root `database/` layout. Applied ONLY
     * on the new layout — the legacy path keeps the exact legacy names.
     *
     * @var array<string, string>
     */
    // NOTE: the new layout normalizes the latent legacy case mismatch where
    // DumpCommand WROTE schema dumps to `Migrations/` but reads expected
    // `migrations/` — both fold to lowercase `migrations` here. On the legacy
    // `{app|src}/Database` fallback the exact legacy names are preserved, so
    // that bug stays latent there (flagged for the 11.4 changelog).
    private const DATABASE_SUB_MAP = [
        'Schema' => 'schemas',     // MigrateCommand / SchemaCommand / DumpCommand --prune
        'Schemas' => 'schemas',
        'Migrations' => 'migrations', // DumpCommand schema dumps
        'Seeders' => 'seeders',    // SeederCommand
        'Factories' => 'factories',
        'Backups' => 'backups',
    ];

    /**
     * Database folder. Since 4.4 the Laravel-standard host-root `database/`
     * directory wins whenever it exists (migrations/, seeders/, schemas/,
     * factories/, backups/ — legacy-cased sub-names are normalized onto it);
     * legacy `{app|src}/Database` is the preserved fallback, byte-identical
     * to the pre-4.4 resolution including the exact subfolder names.
     *
     * @param string $file
     * @return string
     */
    public static function database(string $file = ''): string
    {
        if (self::usesDatabaseLayout()) {
            return self::base() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . self::databaseSub($file);
        }

        return self::appDir() . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Whether the host uses the Laravel-standard host-root `database/` layout
     * (since 4.4). True when `{root}/database` exists; generators key their
     * target directory and PSR-4 namespace (`Database\Factories`,
     * `Database\Seeders`) off this, falling back to the legacy
     * `{app|src}/Database` + `App\Database\…` namespaces otherwise.
     */
    public static function usesDatabaseLayout(): bool
    {
        return is_dir(self::base() . DIRECTORY_SEPARATOR . 'database');
    }

    /**
     * Normalize the FIRST segment of a database sub-path via DATABASE_SUB_MAP
     * (new-layout resolution only); lowercase/unknown names pass through.
     */
    private static function databaseSub(string $file): string
    {
        $segments = explode('/', $file, 2);
        $segments[0] = self::DATABASE_SUB_MAP[$segments[0]] ?? $segments[0];

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * tests folder at the host-app root (layout independent — host tests live
     * beside config/ and routes/, not under src/ or app/).
     *
     * @param string $file
     * @return string
     */
    public static function tests(string $file = ''): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * bin folder
     *
     * @param string $file
     * @return string
     */
    public static function bin(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * auth folder
     *
     * @param string $file
     * @return string
     */
    public static function auth(string $file): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * templates folder in var folder
     *
     * @param string $file
     * @return string
     */
    public static function templates(string $file): string
    {
        return self::base() . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $file;
    }
}
