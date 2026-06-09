<?php

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
     * Build a fully configured Twig Environment.
     *
     * @param string|null            $source  Template source directory (defaults to app.twig.source).
     * @param array<int,string>      $paths   Additional named namespace paths.
     * @param string|null            $cache   Cache directory (defaults to app.twig.cache).
     */
    public function make(?string $source = null, array $paths = [], ?string $cache = null): Environment
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
        try {
            $csrf = csrfToken(config('app.app_name'));
        } catch (\Throwable $e) {
            Logs::create('view.log')->warning('csrfToken() failed building _csrf_token global: ' . $e->getMessage());
            $csrf = '';
        }
        $env->addGlobal('_csrf_token', $csrf);
    }
}
