<?php

namespace Illuminate\Foundation\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Ions\Bundles\MRoute;
use Ions\Bundles\Path;
use Ions\Support\Arr;
use Ions\Support\Storage;
use Ions\Support\Str;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Routing\Loader\AnnotationDirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class RouteListCommand extends Command
{
    private static $terminalWidthResolver;
    protected $signature = 'route:list {--json : return json}';
    protected $description = 'List all registered routes';

    /**
     * The router instance.
     */
    protected $router;

    /**
     * The table headers for the command.
     *
     * @var string[]
     */
    protected $headers = ['Domain', 'Method', 'URI', 'Name', 'Controller', 'Actions'];

    /**
     * The verb colors for the command.
     *
     * @var array
     */
    protected $verbColors = [
        'ANY' => 'red',
        'GET' => 'blue',
        'HEAD' => '#6C7280',
        'OPTIONS' => '#6C7280',
        'POST' => 'green',
        'PUT' => 'yellow',
        'PATCH' => 'yellow',
        'DELETE' => 'red',
    ];


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        if (empty($routes = $this->getRoutes())) {
             $this->error("Your application doesn't have any routes matching the given criteria.");
             exit();
        }

        $this->displayRoutes($routes);

    }

    protected function captureRoute(string $path,$target): array
    {
        MRoute::$collection = new RouteCollection();

        if ($target === 'php') {
            include_once $path;
            $routes = MRoute::$collection;
        } else {
            $fileLocator = new FileLocator([__DIR__]);
            $loader = new YamlFileLoader($fileLocator);
            $routes = $loader->load($path);
        }
        return $routes->all();
    }

    protected function captureRouteAttribute(string $target_folder): array
    {
        $routes = new RouteCollection();

        $target_folder === 'web' ? $attributes_path = Path::src('Http') : $attributes_path = Path::api();
        if (Storage::exists($attributes_path)) {
            $loader = new AnnotationDirectoryLoader(new FileLocator($attributes_path), new AnnotatedRouteControllerLoader());
            $attributes_routes = $loader->load($attributes_path);
            if (!empty($attributes_routes->all())) {
                $routes->addCollection($attributes_routes);
            }
        }

        return $routes->all();
    }

    /**
     * Compile the routes into a displayable format.
     *
     * @return array
     */
    protected function getRoutes(): array
    {
        $routes_collection = [];
        $routes_collection['attrs/web'] = $this->captureRouteAttribute('web');
        $routes_collection['attrs/api'] = $this->captureRouteAttribute('api');

        $files = Storage::allFiles(Path::route());
        foreach ($files as $file) {
           if(empty($file->getRelativePath())){
               $ext = $file->getExtension();
               $routes_collection[File::name($file).'_'.$ext] = $this->captureRoute($file->getPathName(),$ext);
           }
        }

        $routes = new RouteCollection();
        $routes->add(Str::random(10) . '_schedule', new Route('/cron/schedule', ['_controller' => 'App\Schedule::boot']));
        $routes_collection['global'] = $routes->all();

        $routes = collect($routes_collection)->map(function ($route_domain,$key) {
            return collect($route_domain)->map(function ($single_route, $name) use ($key) {
                return $this->getRouteInformation($key, $single_route, $name);
            });
        })->filter()->all();

        $routes_render = [];
        foreach ($routes as $domain => $route_domain){
            foreach ($route_domain as $item_key => $item){
                $routes_render[$domain.'_'.$item_key] = $item;
            }
        }

       return $this->pluckColumns($routes_render);
    }


    /**
     * Get the route information for a given route.
     *
     * @param $domain
     * @param  $route
     * @param $name
     * @return array
     */
    #[ArrayShape(['domain' => "", 'method' => "string", 'uri' => "mixed", 'name' => "", 'controller' => "mixed", 'actions' => "string"])]
    protected function getRouteInformation($domain, $route, $name): array
    {
        $actions = Arr::where($route->getDefaults(), static function ($item, $key){
            if($key !== '_controller'){
                return '('.$key.')'.$item;
            }
            return false;
        });

        return [
            'domain' => $domain,
            'method' => implode('|', $route->getMethods()),
            'uri' => $route->getPath(),
            'name' => $name,
            'controller' => $route->getDefaults()['_controller'],
            'actions' => Str::replace('&',' | ',Arr::query($actions))
        ];
    }

    /**
     * Get the column names to show (lowercase table headers).
     *
     * @return array
     */
    protected function getColumns(): array
    {
        return array_map('strtolower', $this->headers);
    }

    /**
     * Remove unnecessary columns from the routes.
     *
     * @param  array  $routes
     * @return array
     */
    protected function pluckColumns(array $routes): array
    {
        return array_map(function ($route) {
            return Arr::only($route, $this->getColumns());
        }, $routes);
    }

    /**
     * Display the route information on the console.
     *
     * @param  array  $routes
     * @return void
     */
    protected function displayRoutes(array $routes)
    {
        $routes = collect($routes);

        $this->output->writeln(
            $this->option('json') ? $this->asJson($routes) : $this->forCli($routes)
        );
    }

    /**
     * Get the table headers for the visible columns.
     *
     * @return array
     */
    protected function getHeaders()
    {
        return Arr::only($this->headers, array_keys($this->getColumns()));
    }

    /**
     * Parse the column list.
     *
     * @param  array  $columns
     * @return array
     */
    protected function parseColumns(array $columns)
    {
        $results = [];

        foreach ($columns as $i => $column) {
            if (str_contains($column, ',')) {
                $results = array_merge($results, explode(',', $column));
            } else {
                $results[] = $column;
            }
        }

        return array_map('strtolower', $results);
    }

    /**
     * Convert the given routes to JSON.
     *
     * @param  \Illuminate\Support\Collection  $routes
     * @return string
     */
    protected function asJson($routes)
    {
        return $routes
            ->map(function ($route) {
                return $route;
            })
            ->values()
            ->toJson();
    }

    /**
     * Convert the given routes to regular CLI output.
     *
     * @param  \Illuminate\Support\Collection  $routes
     * @return array
     */
    protected function forCli($routes)
    {
        $routes = $routes->map(
            fn ($route) => [
                'action' => $this->formatActionForCli($route),
                'method' => $route['method'] === '' ? 'ANY' : $route['method'],
                'domain' => $route['domain'],
                'controller' => $route['controller'],
                'name' => $route['name'],
                //'uri' =>  (Str::is('*api*',$route['domain'])) ? (config('app.app_url').'/api'.$route['uri']): (config('app.app_url').$route['uri']),
                'uri' =>  (Str::is('*api*',$route['domain'])) ? ('/api'.$route['uri']): ($route['uri']),
            ],
        );

        $maxMethod = mb_strlen($routes->max('method'));

        $terminalWidth = self::getTerminalWidth();

        return $routes->map(function ($route) use ($maxMethod, $terminalWidth) {
            [
                'action' => $action,
                'domain' => $domain,
                'method' => $method,
                'uri' => $uri,
            ] = $route;


            $action .= ' › ' . Str::replace('_','/',$domain);

            $spaces = str_repeat(' ', max($maxMethod + 6 - mb_strlen($method), 0));

            $dots = str_repeat('.', max(
                $terminalWidth - mb_strlen($method.$spaces.$uri.$action) - 6 - ($action ? 1 : 0), 0
            ));

            $dots = empty($dots) ? $dots : " $dots";


            if ($action && ! $this->output->isVerbose() && mb_strlen($method.$spaces.$uri.$action.$dots) > ($terminalWidth - 6)) {
                $action = substr($action, 0, $terminalWidth - 7 - mb_strlen($method.$spaces.$uri.$dots)).'…';
            }

            $method = Str::of($method)->explode('|')->map(
                fn ($method) => sprintf('<fg=%s>%s</>', $this->verbColors[$method] ?? 'default', $method),
            )->implode('<fg=#6C7280>|</>');

            return [sprintf(
                '  <fg=white;options=bold>%s</> %s<fg=white>%s</><fg=#6C7280>%s %s</>',
                $method,
                $spaces,
                preg_replace('#({[^}]+})#', '<fg=yellow>$1</>', $uri),
                $dots,
                str_replace('   ', ' › ', $action),
            )];
        })->flatten()->filter()->prepend('')->push('')->toArray();
    }

    /**
     * Get the formatted action for display on the CLI.
     *
     * @param  array  $route
     * @return string
     */
    protected function formatActionForCli($route)
    {
        ['controller' => $action, 'name' => $name] = $route;

        if ($action instanceof Closure) {
            return 'Closure';
        }

        $name = $name ? "$name   " : null;

        //return $name.$action;
        return $action;
    }

    /**
     * Get the terminal width.
     *
     * @return int
     */
    public static function getTerminalWidth()
    {
        return is_null(static::$terminalWidthResolver)
            ? (new Terminal)->getWidth()
            : call_user_func(static::$terminalWidthResolver);
    }

    /**
     * Set a callback that should be used when resolving the terminal width.
     *
     * @param  \Closure|null  $resolver
     * @return void
     */
    public static function resolveTerminalWidthUsing($resolver)
    {
        static::$terminalWidthResolver = $resolver;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['json', null, InputOption::VALUE_NONE, 'Output the route list as JSON'],
            ['method', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by method'],
            ['name', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by name'],
            ['path', null, InputOption::VALUE_OPTIONAL, 'Only show routes matching the given path pattern'],
            ['except-path', null, InputOption::VALUE_OPTIONAL, 'Do not display the routes matching the given path pattern'],
            ['reverse', 'r', InputOption::VALUE_NONE, 'Reverse the ordering of the routes'],
            ['sort', null, InputOption::VALUE_OPTIONAL, 'The column (precedence, domain, method, uri, name, action, middleware) to sort by', 'uri'],
        ];
    }
}
