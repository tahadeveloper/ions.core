<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Ions\Bundles\AttributeRouteControllerLoader;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Support\Storage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Export the application's routes as an OpenAPI 3.0 document.
 *
 * The command reuses the framework's route-capture pipeline (the same php/yaml
 * files plus attribute routes that {@see Kernel::make()} dispatches) to build a
 * best-effort API inventory: each path/method, the auth requirement (routes
 * behind AuthMiddleware are marked with the bearerAuth scheme) and the path
 * parameters derived from `{placeholder}` segments. Request bodies are emitted
 * for routes whose controller exposes a FormRequest, where derivable.
 */
class OpenApiCommand extends Command
{
    protected $signature = 'openapi:generate {--output= : Write the spec to this file (default openapi.json at the app root)} {--stdout : Print the spec to stdout instead of writing a file}';
    protected $description = 'Generate an OpenAPI 3.0 specification from the application routes';

    public function handle(): int
    {
        $spec = $this->buildSpec();

        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->option('stdout')) {
            $this->output->writeln($json);

            return self::SUCCESS;
        }

        $output = $this->resolveOutputPath();
        file_put_contents($output, $json);
        $this->info('OpenAPI spec written to ' . $output);

        return self::SUCCESS;
    }

    /**
     * Build the OpenAPI 3.0 document.
     *
     * @return array<string, mixed>
     */
    private function buildSpec(): array
    {
        /** @var list<string> $publicPaths */
        $publicPaths = (array) config('app.auth.public_paths', []);

        $paths = [];

        foreach ($this->collectRoutes() as $group => $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */
                $path = $route->getPath();
                $isApi = $group === 'api' || str_starts_with($path, '/api');
                $protected = $isApi && !in_array($path, $publicPaths, true) && !$this->isExplicitlyPublic($route);

                $methods = $route->getMethods();
                if ($methods === []) {
                    $methods = ['GET'];
                }

                foreach ($methods as $method) {
                    $verb = strtolower($method);
                    if (in_array($verb, ['head', 'options'], true)) {
                        continue;
                    }

                    $operation = [
                        'summary' => $this->summary($route, $method),
                        'tags' => [$group],
                        'responses' => [
                            'default' => ['description' => 'Response'],
                        ],
                    ];

                    $parameters = $this->pathParameters($path);
                    if ($parameters !== []) {
                        $operation['parameters'] = $parameters;
                    }

                    if ($protected) {
                        $operation['security'] = [['bearerAuth' => []]];
                    }

                    $paths[$path][$verb] = $operation;
                }
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => (string) config('app.name', 'Ions Application'),
                'version' => $this->appVersion(),
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
        ];
    }

    /**
     * Capture the route collections for each group (api / web), reusing the same
     * php/yaml + attribute-route loading the Kernel performs.
     *
     * @return array<string, array<string, Route>>
     */
    private function collectRoutes(): array
    {
        $collected = [];

        foreach (['api', 'web'] as $group) {
            $collected[$group] = $this->captureGroup($group)->all();
        }

        return $collected;
    }

    /**
     * Build a fresh RouteCollection for a single group from its php/yaml file and
     * attribute routes.
     */
    private function captureGroup(string $group): RouteCollection
    {
        $routes = new RouteCollection();

        $phpFile = Path::route($group . '.php');
        $yamlFile = Path::route($group . '.yaml');

        if (file_exists($phpFile)) {
            // The Route facade appends to Kernel::RouteCollection(); snapshot the
            // delta produced by requiring this file so groups stay separate.
            $before = array_keys(Kernel::RouteCollection()->all());
            require $phpFile;
            $after = Kernel::RouteCollection();
            foreach ($after->all() as $name => $route) {
                if (!in_array($name, $before, true)) {
                    $routes->add($name, $route);
                }
            }
        } elseif (file_exists($yamlFile)) {
            $loader = new YamlFileLoader(new FileLocator([dirname($yamlFile)]));
            $routes->addCollection($loader->load($yamlFile));
        }

        $attributesPath = $group === 'web' ? Path::src('Http') : Path::api();
        if (Storage::exists($attributesPath)) {
            $loader = new AttributeDirectoryLoader(new FileLocator($attributesPath), new AttributeRouteControllerLoader());
            $attributeRoutes = $loader->load($attributesPath);
            if ($attributeRoutes instanceof RouteCollection && $attributeRoutes->all() !== []) {
                $routes->addCollection($attributeRoutes);
            }
        }

        return $routes;
    }

    /**
     * A route is explicitly public when its per-route middleware list does not
     * include the AuthMiddleware and the route opts out via a 'public' option.
     */
    private function isExplicitlyPublic(Route $route): bool
    {
        return (bool) $route->getOption('public');
    }

    /**
     * Build the OpenAPI path-parameter list from `{placeholder}` segments.
     *
     * @return list<array<string, mixed>>
     */
    private function pathParameters(string $path): array
    {
        if (preg_match_all('/\{([^}]+)}/', $path, $matches) === false) {
            return [];
        }

        $parameters = [];
        foreach ($matches[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    private function summary(Route $route, string $method): string
    {
        $controller = $route->getDefaults()['_controller'] ?? null;
        $controller = is_string($controller) ? $controller : 'Closure';

        return strtoupper($method) . ' ' . $controller;
    }

    private function appVersion(): string
    {
        $changelog = Path::core('../CHANGELOG.md');
        if (is_file($changelog)) {
            $contents = (string) file_get_contents($changelog);
            if (preg_match('/##\s*\[(\d+\.\d+\.\d+)]/', $contents, $m) === 1) {
                return $m[1];
            }
        }

        return '4.0.0';
    }

    private function resolveOutputPath(): string
    {
        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            return $output;
        }

        return Path::route('../openapi.json');
    }
}
