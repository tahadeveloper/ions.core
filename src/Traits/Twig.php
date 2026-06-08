<?php

namespace Ions\Traits;

use Ions\View\ViewFactory;
use Twig\Environment;

trait Twig
{
    public Environment $twig;
    private string $twig_source = '';
    private string $twig_cache = '';
    public array $twig_loader_error = [];

    public function setTwigSource(string $source_path): void
    {
        $this->twig_source = $source_path;
    }

    public function getTwigSource(): string
    {
        return $this->twig_source;
    }

    public function setTwigCache(string $cache_path): void
    {
        $this->twig_cache = $cache_path;
    }

    public function getTwigCache(): string
    {
        return $this->twig_cache;
    }

    public function TwigInit(): void
    {
        /** @var ViewFactory $factory */
        $factory = app('view');

        $source = $this->twig_source ?: null;
        $cache  = $this->twig_cache ?: null;
        $paths  = (array) config('app.twig.paths', []);

        $env = $factory->make($source, $paths, $cache);

        // Propagate any loader errors back to the trait's public property for BC.
        $this->twig_loader_error = $factory->loaderErrors;

        $this->twig = $env;
    }
}
