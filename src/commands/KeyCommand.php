<?php

use Illuminate\Console\Command;
use Ions\Bundles\AppKeys;

class KeyCommand extends Command
{
    protected $signature = 'make:key {--jwt : to create jwt after app key}';
    protected $description = 'Create public key for app and jwt key.';

    public function handle(): void
    {
        AppKeys::createKey();
        $this->info('Key created successfully, happy to see you.');
        if($this->option('jwt')){
            $jwt_key = AppKeys::createJWT();
            $this->info('Jwt key created successfully, happy to see you.');
            $this->info($jwt_key?->toString());
        }
    }
}
