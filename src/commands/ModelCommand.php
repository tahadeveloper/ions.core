<?php

use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

/**
 * make:model — generate an Eloquent model in app/Models (App\Models), on the
 * hardened GeneratorCommand base (name validation + --force).
 *
 * The generated model uses the Ions\Database\HasIonsFactory trait live: the
 * trait is inert until factory() is called (no boot/constructor side effects),
 * so a model with no matching factory carries it harmlessly. With --factory the
 * matching Database\Factories\{Name}Factory is generated too (delegated to
 * make:factory), giving zero-config {Name}::factory() resolution.
 *
 * The target dir resolves through Path::src('Models/...'), preserving the
 * src/ -> app/ fallback: on the modern layout the model lands in app/Models.
 */
class ModelCommand extends GeneratorCommand
{
    protected $signature = 'make:model {name} {--factory : Also generate a matching {Name}Factory} {--force : Overwrite the file if it already exists}';

    protected $description = 'Create a new Eloquent model in app/Models (App\\Models).';

    protected function type(): string
    {
        return 'Model';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/model.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Models/' . $name . '.php');
    }

    /**
     * Default placeholder values for a fresh model: timestamps on, a snake_case
     * plural-ish table guessed from the class name, empty fillable/hidden lists
     * for the developer to fill in.
     *
     * @return array<string, string>
     */
    protected function replacements(string $name): array
    {
        return [
            '{{ namespace }}' => 'App\\Models',
            '{{ class }}' => $name,
            '{{ timestamps }}' => 'true',
            '{{ table }}' => Str::snake(Str::pluralStudly($name)),
            '{{ fillable }}' => '',
            '{{ hidden }}' => '',
        ];
    }

    public function handle(): int
    {
        $result = $this->generate();

        if ($result === self::SUCCESS && $this->option('factory')) {
            $name = $this->argument('name');
            $name = is_string($name) ? $name : '';

            $this->call('make:factory', [
                'name' => $name . 'Factory',
                '--model' => 'App\\Models\\' . $name,
            ]);
        }

        return $result;
    }
}
