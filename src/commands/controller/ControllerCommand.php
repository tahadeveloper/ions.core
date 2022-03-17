<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Auth\Guard\GuardControl;
use Ions\Bundles\Path;

class ControllerCommand extends Command
{
    protected $signature = 'make:control
        {name}
        {--path= : path for controller folder}
        {--smarty : choose smarty template}';
    protected $description = 'Create control in http controllers.';

    public function handle(): void
    {
        $name = $this->argument('name'); // ExampleTextController
        $name_cap = Str::remove('Controller', $name); // ExampleText
        $name_snake = Str::snake($name_cap); // Example_Text
        $name_dash = Str::lower(Str::snake($name_cap, '-')); // example-text
        $name_lower = Str::lower($name_snake); // example_text

        $name_space = 'App\\Http\\Controllers';
        $controller_path = 'Http/Controllers';
        $route_file = 'web';

        $template = 'twig';
        $html_name = $name_lower;
        $html_ext = '.html.twig';
        $html_main_folder = config('app.twig.theme');
        if ($this->input->getOption('smarty')) {
            $template = 'smarty';
            $html_ext = '.html.tpl';
            $html_main_folder = config('app.smarty.theme');
        }
        $html_path = 'views/' . $html_main_folder;

        $path = $this->input->getOption('path');

        $stub_controller = 'controller.stub';

        if ($path) {

            $root_folder = $html_main_folder = $path;
            $name_space = 'App\\Http\\'.$path;
            $controller_path = 'Http/'.$path;
            $html_path = 'views/' . $root_folder;
            $html_name = '@'.$html_main_folder.'/'.$name_lower;
            if ($this->input->getOption('smarty')) {
                $html_name = '['.$root_folder.']/'.$name_lower;
            }

            // super -- add control and actions
            if (Str::lower($root_folder) === 'super') {
                $this->addToDB($name_dash, $name_cap);

                $stub_controller = 'controller_super.stub';
            }
        }

        $new_controller = Path::src($controller_path . DIRECTORY_SEPARATOR . $name . '.php');
        Storage::copy(Path::bin('commands/controller/' . $stub_controller), $new_controller);

        $replace = Str::replace(
            ['{{ namespace }}', '{{ class }}', '{{ template }}', '{{ html_name }}', '{{ html_ext }}'],
            [$name_space, $name, $template, $html_name, $html_ext],
            Storage::get($new_controller));

        $html_name = $name_lower;

        Storage::put($new_controller, $replace);

        $this->info('Controller created successfully, happy to see you.');

        $pre_folder = 'Controllers\\';
        if ($path) {
            $pre_folder = $path.'\\';
        }

        // routing
        $this->routing($route_file, $name_lower, $name_dash, $pre_folder, $name);

        // create folder if not , add file html empty
        $this->template($html_path, $html_name, $html_ext);

    }

    /**
     * @param string $route_file
     * @param string $name_lower
     * @param string $name_dash
     * @param string $pre_folder
     * @param array|string|null $name
     * @return void
     */
    public function routing(string $route_file, string $name_lower, string $name_dash, string $pre_folder, array|string|null $name): void
    {
        if (Storage::exists(Path::route($route_file . '.yaml'))) {
            $route_yaml_replace = Str::replace(
                ['{{ route_name }}', '{{ route_path }}', '{{ class }}'],
                [$name_lower, $name_dash, $pre_folder . $name],
                Storage::get(Path::bin('commands/controller/route_yaml.stub')));
            Storage::append(Path::route($route_file . '.yaml'), $route_yaml_replace);

            $this->info('Added to route YAML file');
        }
        if (Storage::exists(Path::route($route_file . '.php'))) {
            $route_php_replace = Str::replace(
                ['{{ route_name }}', '{{ route_path }}', '{{ class }}'],
                [$name_lower, $name_dash, $pre_folder . $name],
                Storage::get(Path::bin('commands/controller/route_php.stub')));
            Storage::append(
                Path::route($route_file . '.php'),
                $route_php_replace
            );

            $this->info('Added to route PHP file');
        }
    }

    /**
     * @param string $html_path
     * @param string $html_name
     * @param string $html_ext
     * @return void
     */
    public function template(string $html_path, string $html_name, string $html_ext): void
    {
        if (!Storage::exists($html_path . '/' . $html_name)) {
            Storage::makeDirectory($html_path . '/' . $html_name);
        }
        Storage::copy(Path::bin('commands/controller/template.stub'), $html_path . '/' . $html_name . '/index' . $html_ext);
        $this->info('Html created successfully.');
    }

    /**
     * @param string $name_dash
     * @param string $name_cap
     * @return void
     */
    public function addToDB(string $name_dash, string $name_cap): void
    {
        $params = [
            'slug' => $name_dash,
            'parent_id' => null,
            'status' => 1,
            'icon' => '',
            'link' => $name_dash,
            'active_name' => $name_dash,
            'languages' => [
                ['language_name' => 'ar', 'name' => Str::headline($name_cap)],
                ['language_name' => 'en', 'name' => Str::headline($name_cap)],
            ],
            'actions' => [
                [
                    'slug' => 'index',
                    'status' => 0,
                    'languages' => [
                        ['language_name' => 'ar', 'action_name' => 'عرض'],
                        ['language_name' => 'en', 'action_name' => 'View'],
                    ]
                ],
                [
                    'slug' => 'add',
                    'status' => 0,
                    'languages' => [
                        ['language_name' => 'ar', 'action_name' => 'الإضافة'],
                        ['language_name' => 'en', 'action_name' => 'Add'],
                    ]
                ],
                [
                    'slug' => 'edit',
                    'status' => 0,
                    'languages' => [
                        ['language_name' => 'ar', 'action_name' => 'تعديل'],
                        ['language_name' => 'en', 'action_name' => 'Edit'],
                    ]
                ],
                [
                    'slug' => 'destroy',
                    'status' => 0,
                    'languages' => [
                        ['language_name' => 'ar', 'action_name' => 'حذف'],
                        ['language_name' => 'en', 'action_name' => 'Delete'],
                    ]
                ]
            ]
        ];
        try {
            $params = json_decode(json_encode($params, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            GuardControl::add($params);
        } catch (Throwable $exception) {
            $this->comment('Can not add to database as control:' . $exception->getMessage());
        }
    }
}
