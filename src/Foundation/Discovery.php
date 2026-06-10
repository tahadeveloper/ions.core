<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Composer\Autoload\ClassLoader;
use Ions\Bundles\Path;
use Ions\Container\ServiceProvider;
use ReflectionClass;
use Throwable;

/**
 * Zero-config service-provider discovery.
 *
 * Three sources, merged in this order (host last, so its providers can
 * override behaviour established by earlier ones):
 *
 *   1. Framework defaults — {@see Kernel::defaultProviders()}.
 *   2. Installed composer packages declaring `extra.ions.providers` (an array
 *      of ServiceProvider FQCNs) in their composer.json — read once per
 *      process from vendor/composer/installed.json and memoized.
 *   3. The host application's `{src|app}/Providers/*.php` classes — a single
 *      is_dir()+glob() per boot (cheap, intentionally not cached so dev edits
 *      apply immediately), mirroring the Console Kernel's command discovery.
 *
 * Only concrete subclasses of {@see ServiceProvider} are ever returned; the
 * result is de-duplicated preserving first occurrence.
 *
 * Discovery is bypassed entirely when the host sets `app.providers` (full
 * explicit control) and disabled via `app.discovery => false` (pure
 * framework defaults) — both handled by Kernel::bootProviders().
 */
final class Discovery extends Singleton
{
    /**
     * Memoized composer-extra scan result (per process).
     *
     * @var list<class-string<ServiceProvider>>|null
     */
    private static ?array $packageProviders = null;

    /**
     * Test seam: when set, used instead of vendor/composer/installed.json.
     * Each entry is a decoded composer.json-shaped package array.
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $metadataOverride = null;

    /**
     * Inject raw package metadata (decoded composer.json arrays) in place of
     * the real vendor/composer/installed.json. Testing seam — clears the memo
     * so the next scan reads the injected set.
     *
     * @param list<array<string, mixed>> $packages
     */
    public static function useMetadata(array $packages): void
    {
        self::$metadataOverride = $packages;
        self::$packageProviders = null;
    }

    /**
     * Clear the package-scan memo and any injected metadata (test isolation;
     * called by Kernel::resetForTesting()).
     */
    public static function reset(): void
    {
        self::$metadataOverride = null;
        self::$packageProviders = null;
    }

    /**
     * The full discovered provider list: framework defaults, then package
     * providers, then host providers — de-duplicated (first occurrence wins,
     * so a class re-declared later keeps its earliest position).
     *
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        /** @var list<class-string<ServiceProvider>> $merged */
        $merged = array_values(array_unique(array_merge(
            Kernel::defaultProviders(),
            self::packageProviders(),
            self::hostProviders(),
        )));

        return $merged;
    }

    /**
     * Scan the host application's {src|app}/Providers directory (preserving
     * the src/ → app/ fallback via Path::src()) for concrete ServiceProvider
     * subclasses. Classes are matched by namespace declaration — the same
     * approach as the Console Kernel's host-command discovery — and files
     * whose class is not autoloadable are included directly, so any host
     * layout works.
     *
     * @return list<class-string<ServiceProvider>>
     */
    public static function hostProviders(): array
    {
        $dir = Path::src('Providers');
        if (!is_dir($dir)) {
            return [];
        }

        $providers = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($files as $file) {
            $class = self::classFromFile($file);
            if ($class === null) {
                continue;
            }

            if (!class_exists($class)) {
                try {
                    require_once $file;
                } catch (Throwable) {
                    continue;
                }
            }

            if (self::isConcreteProvider($class)) {
                /** @var class-string<ServiceProvider> $class */
                $providers[] = $class;
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * Providers declared by installed composer packages under
     * `extra.ions.providers`. Read from vendor/composer/installed.json (the
     * only runtime composer metadata that carries `extra` — installed.php /
     * InstalledVersions::getAllRawData() do not) and memoized per process.
     * Missing/odd metadata (skeleton tests, fixtures without vendor) → [].
     *
     * @return list<class-string<ServiceProvider>>
     */
    public static function packageProviders(): array
    {
        if (self::$packageProviders !== null) {
            return self::$packageProviders;
        }

        $providers = [];
        foreach (self::$metadataOverride ?? self::installedPackages() as $package) {
            $extra = $package['extra'] ?? null;
            $declared = is_array($extra) ? ($extra['ions']['providers'] ?? null) : null;
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $class) {
                if (is_string($class) && self::isConcreteProvider($class)) {
                    /** @var class-string<ServiceProvider> $class */
                    $providers[] = $class;
                }
            }
        }

        return self::$packageProviders = array_values(array_unique($providers));
    }

    /**
     * Locate and decode vendor/composer/installed.json relative to the
     * ClassLoader actually running this process (robust against unusual
     * vendor locations). Any failure yields an empty list — discovery must
     * never break boot.
     *
     * @return list<array<string, mixed>>
     */
    private static function installedPackages(): array
    {
        try {
            if (!class_exists(ClassLoader::class)) {
                return [];
            }
            $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
            if ($loaderFile === false) {
                return [];
            }

            $installedJson = dirname($loaderFile) . DIRECTORY_SEPARATOR . 'installed.json';
            if (!is_file($installedJson)) {
                return [];
            }

            $raw = file_get_contents($installedJson);
            if ($raw === false) {
                return [];
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return [];
            }

            // Composer 2 wraps the list under 'packages'; Composer 1 is bare.
            $packages = $data['packages'] ?? $data;
            if (!is_array($packages)) {
                return [];
            }

            /** @var list<array<string, mixed>> $list */
            $list = array_values(array_filter($packages, 'is_array'));

            return $list;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Whether $class names a loadable, concrete ServiceProvider subclass.
     */
    private static function isConcreteProvider(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, ServiceProvider::class)) {
            return false;
        }

        return !(new ReflectionClass($class))->isAbstract();
    }

    /**
     * Derive the fully-qualified class name from a PHP file by reading its
     * namespace and class declarations (same technique as the Console
     * Kernel's host-command discovery).
     *
     * @return class-string|null
     */
    private static function classFromFile(string $file): ?string
    {
        $contents = (string) file_get_contents($file);

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $m) === 1) {
            $namespace = trim($m[1]) . '\\';
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $m) === 1) {
            /** @var class-string $fqcn */
            $fqcn = $namespace . $m[1];

            return $fqcn;
        }

        return null;
    }
}
