<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ions\Auth\Guard\GuardRole;
use Ions\Auth\Guard\GuardUser;
use Ions\Bundles\Path;
use Ions\Support\Storage;

class SuperCommand extends Command
{
    protected $signature = 'install:super';
    protected $description = 'Create super folder , install database for it.';

    /**
     * @throws Throwable
     * @throws Throwable
     */
    public function handle(): void
    {
        if (!Storage::exists(Path::src('Http/super'))) {
            $zip = new ZipArchive;
            if ($zip->open(Path::templates('zips/super/http/super.zip')) === TRUE) {
                $zip->extractTo(Path::src('Http'));
                $zip->close();
                $this->info('Super created successfully, happy to see you.');

                $this->createSchema();

                // create views
                if (!Storage::exists(Path::views('super'))) {

                    $zip = new ZipArchive;
                    if ($zip->open(Path::templates('zips/super/views/super.zip')) === TRUE) {
                        $zip->extractTo(Path::views(''));
                        $zip->close();
                        $this->info('Super views created successfully, happy to see you.');

                    } else {
                        $this->error('Super views failed.');
                    }

                } else {
                    $this->comment('Super views already installed.');
                }

            } else {
                $this->error('Super http failed.');
            }
        } else {
            $this->comment('Super already installed.');
        }

    }

    /**
     * @return void
     * @throws JsonException
     * @throws Throwable
     */
    private function createSchema(): void
    {
        // create schema
        DB::connection()->unprepared(file_get_contents(Path::bin('commands/super/super_schema.sql')));
        $this->info('Super schema install successfully.');
        // install data
        DB::connection()->unprepared(file_get_contents(Path::bin('commands/super/super_init.sql')));
        $this->info('Super data install successfully.');

        // install main user and role
        $params = [
            'name' => 'admin',
            'slug' => 'admin',
            'languages' => [
                ['language_name' => 'ar', 'name' => 'مدير'],
                ['language_name' => 'en', 'name' => 'Admin'],
            ]
        ];

        $params = json_decode(json_encode($params, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $role_id = GuardRole::add($params);

        $user_params = [
            'email' => 'admin@ionzile.com',
            'first_name' => 'Ion',
            'last_name' => 'Manager',
            'status' => 1,
            'mobile' => '011',
            'mobile_2' => null,
            'password' => '$l^w1f1HozlFo~OKeM',
            'address' => '',
            'notes' => '',
            'image' => null,
            'image_name' => null,
            'role_id' => $role_id
        ];
        GuardUser::add((object)$user_params, true);

        $this->info('Super main role added successfully.');
    }
}
