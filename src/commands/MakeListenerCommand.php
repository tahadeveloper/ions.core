<?php

use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeListenerCommand extends GeneratorCommand
{
    protected $signature = 'make:listener {name} {--event= : The event class to type-hint in handle() (short name resolves to App\Events\…)} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new event listener class with a handle($event) method.';

    protected function type(): string
    {
        return 'Listener';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/listener.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Listeners/' . $name . '.php');
    }

    protected function prepare(string $name): ?int
    {
        $event = $this->eventOption();

        if ($event !== null && !$this->isValidClassReference($event)) {
            $this->error('Invalid event class: ' . $event . ' (expected a class name or FQCN)');

            return self::FAILURE;
        }

        return null;
    }

    protected function replacements(string $name): array
    {
        // Default: untyped handle(object $event). With --event the event class
        // is imported and type-hinted; a bare name resolves to App\Events\…
        $imports = '';
        $eventType = 'object';

        $event = $this->eventOption();
        if ($event !== null) {
            $event = trim($event, '\\');
            $fqcn = Str::contains($event, '\\') ? $event : 'App\\Events\\' . $event;
            $eventType = Str::afterLast($fqcn, '\\');
            $imports = 'use ' . $fqcn . ';' . PHP_EOL . PHP_EOL;
        }

        return [
            '{{ class }}' => $name,
            '{{ imports }}' => $imports,
            '{{ event }}' => $eventType,
        ];
    }

    private function eventOption(): ?string
    {
        $event = $this->option('event');

        return is_string($event) && $event !== '' ? $event : null;
    }
}
