<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeListenerCommand extends Command
{
    protected $signature = 'make:listener {name} {--event= : The event class to type-hint in handle() (short name resolves to App\Events\…)} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new event listener class with a handle($event) method.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Listeners'))) {
            File::makeDirectory(Path::src('Listeners'), 0755, true, true);
        }

        $new_file = Path::src('Listeners/' . $name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Listener already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        // Default: untyped handle(object $event). With --event the event class
        // is imported and type-hinted; a bare name resolves to App\Events\…
        $imports = '';
        $eventType = 'object';

        $event = $this->option('event');
        if (is_string($event) && $event !== '') {
            $event = trim($event, '\\');
            $fqcn = Str::contains($event, '\\') ? $event : 'App\\Events\\' . $event;
            $eventType = Str::afterLast($fqcn, '\\');
            $imports = 'use ' . $fqcn . ';' . PHP_EOL . PHP_EOL;
        }

        Storage::copy(Path::bin('commands/stubs/listener.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}', '{{ imports }}', '{{ event }}'],
            [$name, $imports, $eventType],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Listener created successfully: ' . $name);

        return self::SUCCESS;
    }
}
