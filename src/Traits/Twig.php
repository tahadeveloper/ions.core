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

        // Pass source/cache overrides; let ViewFactory::make() own the path-config default chain
        // (it already reads config('app.twig.paths') when $paths is empty), avoiding a double read.
        $env = $factory->make($this->twig_source ?: null, [], $this->twig_cache ?: null);

        // Propagate any loader errors back to the trait's public property for BC.
        $this->twig_loader_error = $factory->loaderErrors;

        $this->twig = $env;
    }
}
