<?php

namespace Ions\Traits;

use Ions\Bundles\Path;
use Smarty as SmartyBundle;


trait Smarty
{
    public SmartyBundle $smarty;

    public function smartyInit(): void
    {
        $environment = new SmartyBundle();
        $this->loader($environment);

        $environment->setCompileDir(config('app.smarty.compile', Path::cache('smarty/compile')));
        $environment->setCacheDir(config('app.smarty.cache', Path::cache('smarty/cache')));
        $environment->setConfigDir(config('app.smarty.config', Path::config('smarty.php')));
        $environment->registerClass('Path', Path::class);
        $environment->assignGlobal('appUrl', config('app.app_url'));
        $this->smarty =  $environment;
    }

    /**
     * @param SmartyBundle $environment
     * @return void
     */
    private function loader(SmartyBundle $environment): void
    {
        $source = config('app.smarty.source', Path::views('default'));
        $environment->setTemplateDir($source);
        $paths = config('app.smarty.paths',[]);
        foreach ($paths as $path){
            $environment->addTemplateDir(Path::views($path),$path);
        }
    }

}