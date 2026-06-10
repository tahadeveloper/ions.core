<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeFactoryCommand extends GeneratorCommand
{
    protected $signature = 'make:factory {name} {--model= : Fully-qualified model class (default: App\{Name} with the Factory suffix stripped)} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new model factory in {src|app}/Factories (resolved by HasIonsFactory for App\\ models).';

    protected function type(): string
    {
        return 'Factory';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/factory.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Factories/' . $name . '.php');
    }

    protected function prepare(string $name): ?int
    {
        $model = $this->option('model');

        if (is_string($model) && $model !== '' && !$this->isValidClassReference($model)) {
            $this->error('Invalid model class: ' . $model . ' (expected a class name or FQCN like App\\User)');

            return self::FAILURE;
        }

        return null;
    }

    protected function replacements(string $name): array
    {
        $model = $this->option('model');

        if (!is_string($model) || $model === '') {
            // UserFactory -> App\User; names without the suffix map verbatim.
            $model = 'App\\' . (preg_replace('/Factory$/', '', $name) ?: $name);
        }

        return [
            '{{ class }}' => $name,
            '{{ model }}' => ltrim($model, '\\'),
        ];
    }
}
