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
     * @param string|null            $source  Template source directory (defaults to app.twig.source).
     * @param array<int,string>      $paths   Additional named namespace paths.
     * @param string|null            $cache   Cache directory (defaults to app.twig.cache).
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
     * @param string|null            $source  Template source directory (defaults to app.twig.source).
     * @param array<int,string>      $paths   Additional named namespace paths.
     * @param string|null            $cache   Cache directory (defaults to app.twig.cache).
     */
    public function build(?string $source = null, array $paths = [], ?string $cache = null): Environment
    {
        $this->loaderErrors = [];

        $source ??= config('app.twig.source', Path::views('default'));
        $cache  ??= config('app.twig.cache', Path::cache('twig'));
        $paths    = $paths ?: (array) config('app.twig.paths', []);

        $loader = new FilesystemLoader($source);
        foreach ($paths as $path) {
            try {
                $loader->addPath(Path::views($path), $path);
            } catch (LoaderError $e) {
                $this->loaderErrors[] = $e->getMessage();
            }
        }

        $env = new Environment($loader, [
            'debug'       => (bool) env('APP_DEBUG', false),
            'auto_reload' => (bool) env('APP_DEBUG', false),
            'charset'     => 'UTF-8',
            'cache'       => $cache,
        ]);

        $this->addCoreFunctions($env);

        return $env;
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

        $env->addGlobal('appUrl', config('app.app_url'));

        // _trans: only call trans() when Localization has been initialised (Localization::init()
        // must have been called first in _loadInit()). The explicit isset() guard avoids swallowing
        // unrelated errors that a broad catch would hide.
        $env->addGlobal('_trans', isset(Localization::$localization) ? trans() : '');

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
        $env->addGlobal('_csrf_token', $csrf);
    }
}
