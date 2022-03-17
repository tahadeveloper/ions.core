<?php

namespace Ions\Traits;

use Ions\Bundles\Path;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

trait Twig
{
    public Environment $twig;
    private string $twig_source = '';
    private string $twig_cache = '';
    public array $twig_loader_error = [];

    public function setTwigSource($source_path): void
    {
        $this->twig_source = $source_path;
    }

    public function getTwigSource(): string
    {
        return $this->twig_source;
    }

    public function setTwigCache($cache_path): void
    {
        $this->twig_cache = $cache_path;
    }

    public function getTwigCache(): string
    {
        return $this->twig_cache;
    }

    public function TwigInit(): void
    {
        $source = $this->twig_source;
        if(empty($this->twig_source)){
            $source = config('app.twig.source', Path::views('default'));
        }

        $cache = $this->twig_cache;
        if(empty($this->twig_cache)){
            $cache = config('app.twig.cache', Path::cache('twig'));
        }


        $loader = $this->twigLoader($source,config('app.twig.paths'));
        $environment = new Environment($loader, [
            'debug' => config('app.app_debug', false),
            'auto_reload' => config('app.app_debug', false),
            'charset' => 'UTF-8',
            'cache' => $cache,
        ]);


        $this->options($environment);

        $this->twig = $environment;
    }

    /**
     * @param Environment $environment
     * @return void
     */
    private function options(Environment $environment): void
    {
        $environment->addFunction(new TwigFunction('config', fn($key = null) => config($key)));
        $environment->addFunction(new TwigFunction('trans',
            fn(string|null $key = '', array $replace = [], string|null $domain = null, string|null $locale = null) => trans($key, $replace, $domain, $locale)));
        $environment->addFunction(new TwigFunction('assets', fn(string $url,string $folder = 'default') => Path::assets($url,$folder)));
        $environment->addFunction(new TwigFunction('public', fn(string $url) => Path::public($url,true)));
        $environment->addFunction(new TwigFunction('files', fn(string $url) => Path::files($url, true)));
        $environment->addFunction(new TwigFunction('appUrl', fn(string $url = '',string $folder = null) => Path::rootFolder($url, $folder)));
        $environment->addFunction(new TwigFunction('ionToken',
            fn(string $form_name) => new Markup(ionToken($form_name), 'UTF-8')));
        $environment->addGlobal('appUrl', config('app.app_url'));
        $environment->addGlobal('_trans', trans());
    }

    /**
     * @param mixed $source
     * @param array $paths
     * @return FilesystemLoader
     */
    private function twigLoader(mixed $source,array $paths = []): FilesystemLoader
    {
        $loader = new FilesystemLoader($source);
        foreach ($paths as $path){
            try {
                $loader->addPath(Path::views($path), $path);
            } catch (LoaderError $exception) {
                $this->twig_loader_error[] = $exception->getMessage();
            }
        }
        return $loader;
    }

}