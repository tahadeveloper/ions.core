<?php

namespace Ions\Bundles;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem;
use JetBrains\PhpStorm\Pure;

class Cache extends Repository
{
    #[Pure] public function __construct()
    {
        $file_store = new FileStore(
            new Filesystem\Filesystem(),
            Path::cache('app')
        );
        parent::__construct($file_store);
    }
}
