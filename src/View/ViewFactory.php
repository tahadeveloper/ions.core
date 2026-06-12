<?php

declare(strict_types=1);

namespace Ions\View;

use Ions\Bundles\Localization;
use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class ViewFactory
{
    /** @var string[] */
    public array $loaderErrors = [];

    /**
     * Return a fully configured Twig Environment.
     *
     * A no-override call reuses the shared per-process Environment bound as
     * 'view.env' (registered by ViewProvider) — building a Twig Environment
     * (FilesystemLoader + function/global registration) per render is wasted
     * work. Explicit overrides, or the container being absent, always build
     * a fresh instance.
     *
     * @param string|null              $source  Template source directory (defaults to app.twig.source).
     * @param array<int|string,string> $paths   Named namespace paths (see build()).
     * @param string|null              $cache   Cache directory (defaults to app.twig.cache).
     */
    public function make(?string $source = null, array $paths = [], ?string $cache = null): Environment
    {
        if ($source === null && $paths === [] && $cache === null) {
            $shared = $this->sharedEnvironment();
            if ($shared !== null) {
                return $shared;
            }
        }

        return $this->build($source, $paths, $cache);
    }

    /**
     * Resolve the shared 'view.env' singleton from the booted container, or
     * null when no container/binding is available (isolated scripts, tests
     * that construct the factory directly).
     */
    private function sharedEnvironment(): ?Environment
    {
        try {
            $container = \Ions\Foundation\Kernel::app();
            if ($container->bound('view.env')) {
                $env = $container->get('view.env');

                return $env instanceof Environment ? $env : null;
            }
        } catch (\Throwable) {
            // Container not booted — fall through to a fresh build.
        }

        return null;
    }

    /**
     * Always build a fresh, fully configured Twig Environment.
     *
     * $paths registers named loader namespaces (templates address them as
     * '@name/...'). Two entry shapes are accepted:
     *  - 'name' => 'dir' (4.2): relative dirs resolve from the HOST ROOT,
     *    absolute dirs (vendor packages) are kept as-is;
     *  - legacy list entry (pre-4.2 BC): the value is both the namespace
     *    name and a folder under views/.
     * A missing namespace dir is skipped — recorded in $loaderErrors and
     * logged — because the env is built once per process and an optional
     * namespace must never kill boot.
     *
     * @param string|null              $source  Template source directory (defaults to app.twig.source).
     * @param array<int|string,string> $paths   Named namespace paths (defaults to app.twig.paths).
     * @param string|null              $cache   Cache directory (defaults to app.twig.cache).
     */
    public function build(?string $source = null, array $paths = [], ?string $cache = null): Environment
    {
        $this->loaderErrors = [];

        $source ??= config('app.twig.source', Path::views('default'));
        $cache  ??= config('app.twig.cache', Path::cache('twig'));
        $paths    = $paths ?: (array) config('app.twig.paths', []);

        $loader = new FilesystemLoader($source);
        foreach ($paths as $name => $path) {
            try {
                if (is_string($name)) {
                    $loader->addPath(self::isAbsolutePath($path) ? $path : Path::root($path), $name);
                } else {
                    // Legacy list entry: namespace == value, dir under views/.
                    $loader->addPath(Path::views($path), $path);
                }
            } catch (LoaderError $e) {
                $this->loaderErrors[] = $e->getMessage();
                Logs::create('view.log')->warning('Twig namespace skipped: ' . $e->getMessage());
            }
        }

        $env = new Environment($loader, [
            'debug'       => (bool) env('APP_DEBUG', false),
            'auto_reload' => (bool) env('APP_DEBUG', false),
            'charset'     => 'UTF-8',
            'cache'       => $cache,
        ]);

        $this->addCoreFunctions($env);
        $env->addExtension(new AssetExtension());
        $env->addExtension(new PaginationExtension());

        return $env;
    }

    /**
     * Whether $path is absolute (unix, windows drive, or UNC).
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function addCoreFunctions(Environment $env): void
    {
        $env->addFunction(new TwigFunction('config', fn ($key = null) => config($key)));
        $env->addFunction(new TwigFunction(
            'trans',
            fn (?string $key = '', array $replace = [], ?string $domain = null, ?string $locale = null) => trans($key, $replace, $domain, $locale)
        ));
        $env->addFunction(new TwigFunction('assets', fn (string $url, string $folder = 'default') => Path::assets($url, $folder)));
        $env->addFunction(new TwigFunction('public', fn (string $url) => Path::public($url, true)));
        $env->addFunction(new TwigFunction('files', fn (string $url) => Path::files($url, true)));
        $env->addFunction(new TwigFunction('appUrl', fn (string $url = '', ?string $folder = null) => Path::rootFolder($url, $folder)));
        $env->addFunction(new TwigFunction(
            'ionToken',
            fn (string $form_name, string $input_name = '_ion_token') => new Markup(ionToken($form_name, $input_name), 'UTF-8')
        ));

        // Web form flow (10.3): errors()/old() are FUNCTIONS, not globals —
        // they read the session flash lazily at render time, so a shared
        // per-process Environment always sees the CURRENT request's data
        // (no refreshRequestGlobals() involvement needed).
        $env->addFunction(new TwigFunction('errors', static fn (): \Ions\Http\ErrorBag => errors()));
        $env->addFunction(new TwigFunction('old', static fn (string $key, mixed $default = null): mixed => old($key, $default)));

        // Authorization (10.4): can() defers to the gate AT RENDER TIME (same
        // lazy pattern as errors()/old()), so a shared per-process Environment
        // always checks the CURRENT request's user.
        $env->addFunction(new TwigFunction('can', static fn (string $ability, mixed ...$args): bool => can($ability, ...$args)));

        foreach ($this->requestGlobals() as $name => $value) {
            $env->addGlobal($name, $value);
        }
    }

    /**
     * Re-evaluate the per-request Twig globals on an already-built Environment.
     *
     * Twig globals are plain values frozen at registration time; on a shared
     * per-process Environment (worker mode) `_csrf_token` would otherwise be
     * the FIRST request's token — stale and cross-user. Kernel::resetForRequest()
     * calls this so every request renders with fresh values. Twig allows
     * updating an EXISTING global after the runtime is initialised, which is
     * why the same names registered in addCoreFunctions() are re-set here.
     *
     * Design note: lazy (per-access) globals were considered, but Twig globals
     * cannot be closures and wrapping them in stringable proxies changes their
     * type in templates (`{% if _trans %}`, comparisons), so explicit
     * re-registration on reset was chosen instead.
     */
    public function refreshRequestGlobals(Environment $env): void
    {
        foreach ($this->requestGlobals() as $name => $value) {
            $env->addGlobal($name, $value);
        }
    }

    /**
     * Compute the current values of the per-request globals.
     *
     * @return array<string, mixed>
     */
    private function requestGlobals(): array
    {
        // _trans: only call trans() when Localization has been initialised (Localization::init()
        // must have been called first in _loadInit()). The explicit isset() guard avoids swallowing
        // unrelated errors that a broad catch would hide.
        $trans = isset(Localization::$localization) ? trans() : '';

        // _csrf_token: csrfToken() uses NativeSessionTokenStorage which can fail in CLI/test SAPI.
        // A narrow try/catch is required here; unlike _trans there is no cheap precondition we can
        // check, so we log any failure (making it visible in production) before falling back to ''.
        // Always use the CsrfMiddleware token id ('web') so the rendered _csrf_token
        // validates in the pipeline. Using app_name here would issue tokens under an id
        // the middleware never checks (419 on every form for hosts that set app_name).
        try {
            $csrf = csrfToken('web');
        } catch (\Throwable $e) {
            Logs::create('view.log')->warning('csrfToken() failed building _csrf_token global: ' . $e->getMessage());
            $csrf = '';
        }

        // tJson: the locale translations as JSON, consumed by client-side i18n.
        // Seeded here (even as '') so the global is REGISTERED at env-build time.
        // BaseController::_loadInit() and the render() helper later call
        // addGlobal('tJson', …) once Localization::init() has run — Twig forbids
        // ADDING a new global after the environment is initialized but allows
        // UPDATING an existing one, so pre-registering it keeps those per-request
        // calls safe. Without this, a process that first initializes the shared
        // env via some other render (e.g. a Mailable's Twig view) — common in
        // worker mode — would throw "Unable to add global \"tJson\"" on the next
        // controller render.
        $tJson = '';
        if (isset(Localization::$localization)) {
            $json = Localization::localeJson((string) config('app.localization.locale', 'en'));
            $tJson = is_string($json) ? $json : '';
        }

        return [
            'appUrl' => config('app.app_url'),
            '_trans' => $trans,
            '_csrf_token' => $csrf,
            'tJson' => $tJson,
        ];
    }
}
