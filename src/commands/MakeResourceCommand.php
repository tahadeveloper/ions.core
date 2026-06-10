<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeResourceCommand extends Command
{
    protected $signature = 'make:resource {name} {--collection : Generate a ResourceCollection instead of a single Resource} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new API resource class (Ions\Http\Resource / ResourceCollection).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Http/Resources'))) {
            File::makeDirectory(Path::src('Http/Resources'), 0755, true, true);
        }

        $new_file = Path::src('Http/Resources/' . $name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Resource already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        if ($this->option('collection')) {
            // UserCollection -> UserResource, Users -> UsersResource
            $resource = Str::replaceLast('Collection', '', $name);
            if (!Str::endsWith($resource, 'Resource')) {
                $resource .= 'Resource';
            }

            Storage::copy(Path::bin('commands/stubs/resource_collection.stub'), $new_file);

            $replace = Str::replace(
                ['{{ class }}', '{{ resource }}'],
                [$name, $resource],
                Storage::get($new_file)
            );
        } else {
            Storage::copy(Path::bin('commands/stubs/resource.stub'), $new_file);

            $replace = Str::replace(
                ['{{ class }}'],
                [$name],
                Storage::get($new_file)
            );
        }

        Storage::put($new_file, $replace);

        $this->info('Resource created successfully: ' . $name);

        return self::SUCCESS;
    }
}
