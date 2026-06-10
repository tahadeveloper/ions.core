<?php

use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeResourceCommand extends GeneratorCommand
{
    protected $signature = 'make:resource {name} {--collection : Generate a ResourceCollection instead of a single Resource} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new API resource class (Ions\Http\Resource / ResourceCollection).';

    protected function type(): string
    {
        return 'Resource';
    }

    protected function stubPath(): string
    {
        $stub = $this->option('collection') ? 'resource_collection.stub' : 'resource.stub';

        return Path::bin('commands/stubs/' . $stub);
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Http/Resources/' . $name . '.php');
    }

    protected function prepare(string $name): ?int
    {
        if ($this->option('collection')) {
            // Surface the derived resource class so the assumption is visible.
            $this->info('Wiring collection to ' . $this->resourceClassFor($name) . '::class — create it with make:resource if missing.');
        }

        return null;
    }

    protected function replacements(string $name): array
    {
        if (!$this->option('collection')) {
            return parent::replacements($name);
        }

        return [
            '{{ class }}' => $name,
            '{{ resource }}' => $this->resourceClassFor($name),
        ];
    }

    /** UserCollection -> UserResource, Users -> UsersResource */
    private function resourceClassFor(string $name): string
    {
        $resource = Str::replaceLast('Collection', '', $name);

        if (!Str::endsWith($resource, 'Resource')) {
            $resource .= 'Resource';
        }

        return $resource;
    }
}
