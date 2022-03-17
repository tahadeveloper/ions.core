<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Ions\Bundles\Path;
use Ions\Support\File;
use JetBrains\PhpStorm\Pure;

class ModelCommand extends Command
{
    protected $signature = 'make:model {name}';
    protected $description = 'Create model for table in database.';

    public function handle(): void
    {
        $name = $this->argument('name'); // ExampleTextModel
        $name_cap = Str::remove('Model', $name); // ExampleText
        $name_snake = Str::snake($name_cap); // Example_Text
        $name_lower = Str::lower($name_snake); // example_text

        if (!File::exists(Path::src('Models'))) {
            File::makeDirectory(Path::src('Models'), 0755, true, true);
        }

        $new_file = Path::src('Models/' . $name . '.php');
        Storage::copy(Path::bin('commands/stubs/model.stub'), $new_file);

        $timestamps = 'true';
        $fillable = '';
        $hidden = '';
        $import = '';
        $use = '';
        $properties = '';

        try {
            $fields = Schema::connection('default')->getColumnListing($name_lower);
        }catch (Throwable){
            //ignore
            $fields = [];
        }

        $hide = ['password', 'secret'];
        $avoid = ['id', 'ID', 'verified', 'active'];

        if (!empty($fields)) {
            foreach ($fields as $property) {
                if ($isDate = Str::endsWith($property, '_at')) {
                    $avoid[] = $property;
                }
                if ($is_id = Str::endsWith($property, '_id')) {
                    $avoid[] = $property;
                }
                [$type, $subType] = $this->guessType($property);
                $pt = in_array($property, $hide, true)
                    ? '@property-write'
                    : (in_array($property, $avoid,true) || $isDate ? '@property-read ' : '@property      ');
                $properties .= "$pt $type \${$property}$subType\n * ";
            }

            $timestamps = in_array('created_at', $fields, true) ? 'true' : 'false';
            $fields = array_diff($fields, $avoid);
            $hide = array_intersect($fields, $hide);

            $fillable = "'" . implode("',\n        '", $fields) . "'";

            if (in_array('deleted_at', $fields, true)) {
                $import = "use Illuminate\\Database\\Eloquent\\SoftDeletingTrait;\n\n";
                $use = "use SoftDeletingTrait;\n\n    protected \$dates = ['deleted_at'];\n        ";
            }
        }

        if (!empty($hide)) {
            $hidden = "'" . implode("',\n        '", $hide) . "'";
        }

        $replace = Str::replace(
            ['{{ namespace }}', '{{ import }}', '{{ class }}', '{{ properties }}', '{{ use }}', '{{ timestamps }}', '{{ table }}', '{{ fillable }}', '{{ hidden }}'],
            ['App\\Models', $import, $name, $properties, $use, $timestamps, $name_lower, $fillable, $hidden],
            Storage::get($new_file));

        Storage::put($new_file, $replace);

        $this->info('Model created successfully, happy to see you.');
    }

    #[Pure] protected function guessType($name): array
    {
        $subtype = '';
        $type = 'string';
        if (Str::endsWith($name, '_at')) {
            //date field
            $subtype = ' {@type date}';
        } elseif (Str::endsWith($name, ['id', 'ID'])) {
            //int
            $type = 'int   ';
        } // else //assume string

        return [$type, $subtype];
    }

}
