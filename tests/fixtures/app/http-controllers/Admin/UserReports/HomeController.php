<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Admin\UserReports;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Nested fixture controller: the class name is dropped, the folder path is
 * kebab-cased — Admin\UserReports\HomeController -> views/admin/user-reports/.
 */
class HomeController extends BaseController
{
    public function index(Request $request): View
    {
        return $this->view('index', ['who' => 'user-reports']);
    }
}
