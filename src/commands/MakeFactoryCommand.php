<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeFactoryCommand extends GeneratorCommand
{
    protected $signature = 'make:factory {name} {--model= : Fully-qualified model class (default: App\{Name} with the Factory suffix stripped)} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new model factory in {app|src}/Factories (resolved by HasIonsFactory for App\\ models).';

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

    public function handle(): int
    {
        $result = $this->generate();

        if ($result === self::SUCCESS) {
            $this->noteFactoryNamespaceMismatch();
        }

        return $result;
    }

    /**
     * The factory is always generated in App\Factories, but HasIonsFactory
     * resolves {ModelNamespace}\Factories\{Model}Factory by convention — for
     * a --model outside the App\ namespace the pair will not resolve, so tell
     * the user how to wire it.
     */
    private function noteFactoryNamespaceMismatch(): void
    {
        $model = $this->option('model');

        if (!is_string($model) || $model === '') {
            return; // inferred App\{Name}: the convention resolves App\Factories\{Name}Factory.
        }

        $model = ltrim($model, '\\');
        $separator = strrpos($model, '\\');
        $namespace = $separator === false ? '' : substr($model, 0, $separator);

        if ($namespace === 'App') {
            return;
        }

        $name = $this->argument('name');
        $name = is_string($name) ? $name : '';
        $shortModel = $separator === false ? $model : substr($model, $separator + 1);
        $factoryNamespace = ($namespace === '' ? '' : $namespace . '\\') . 'Factories';

        $this->info(sprintf(
            'Note: HasIonsFactory resolves %s\\%sFactory for this model, not the generated App\\Factories\\%s. ' .
            'Either add `protected static string $factory = \\App\\Factories\\%s::class;` to the model, or move the factory to %s\\.',
            $factoryNamespace,
            $shortModel,
            $name,
            $name,
            $factoryNamespace
        ));
    }
}
