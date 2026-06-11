<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

class HomeController extends BaseController
{
    public function index(Request $request): View
    {
        // Controller-relative view (4.2): HomeController -> views/home/, so
        // this resolves views/home/index.twig. The dispatcher renders the
        // returned View into a 200 HTML response.
        return $this->view('index', [
            'app_name' => config('app.name', 'Ions'),
            'ions_version' => $this->ionsVersion(),
        ]);
    }

    /**
     * The installed framework version for the welcome page's version line —
     * composer runtime metadata, degrading to 'dev' in odd setups.
     */
    private function ionsVersion(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('ionzile/core') ?? 'dev';
        } catch (\Throwable) {
            return 'dev';
        }
    }
}
