<?php

declare(strict_types=1);

namespace Ions\commands;

use Closure;
use Illuminate\Console\Command;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;

/**
 * route:cache — compile the web/api route collections into
 * var/cache/routes/{web|api}.php for fast request matching.
 *
 * The kernel loads these files (when APP_DEBUG is off) and matches via
 * Symfony's CompiledUrlMatcher, skipping the per-request php/yaml parse and
 * attribute reflection scan entirely.
 *
 * Closure-based routes cannot be compiled (a Closure cannot be exported to
 * PHP source); the command fails loudly and names the offending route.
 */
class RouteCacheCommand extends Command
{
    protected $signature = 'route:cache';

    protected $description = 'Compile the application routes into var/cache/routes for fast matching';

    public function handle(): int
    {
        $dir = Path::cache('routes');

        foreach (['web', 'api'] as $group) {
            $routes = Kernel::buildRouteCollection($group);

            foreach ($routes->all() as $name => $route) {
                if ($route->getDefault('_controller') instanceof Closure) {
                    $methods = $route->getMethods();
                    $this->error(sprintf(
                        "Route '%s' (%s %s) in the '%s' group uses a Closure controller and cannot be cached. " .
                        'Point it at a controller class (Controller::method), then re-run route:cache.',
                        $name,
                        $methods === [] ? 'ANY' : implode('|', $methods),
                        $route->getPath(),
                        $group
                    ));

                    return self::FAILURE;
                }

                // Embed per-route middleware names as a default so the compiled
                // matcher returns them with the match (options are not compiled).
                $middleware = (array) ($route->getOption('middleware') ?? []);
                if ($middleware !== []) {
                    $route->addDefaults(['_middleware' => $middleware]);
                }
            }

            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->error("Unable to create cache directory: {$dir}");

                return self::FAILURE;
            }

            $file = $dir . DIRECTORY_SEPARATOR . $group . '.php';
            file_put_contents($file, (new CompiledUrlMatcherDumper($routes))->dump());

            $this->info(sprintf("Route cache written for '%s' (%d routes) -> %s", $group, count($routes->all()), $file));
        }

        return self::SUCCESS;
    }
}
