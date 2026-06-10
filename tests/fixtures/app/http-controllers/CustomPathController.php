<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

/**
 * $viewPath override fixture: derivation would say 'custom-path', the
 * explicit $viewPath wins -> views/custom/place/.
 */
class CustomPathController extends BaseController
{
    protected string $viewPath = 'custom/place';

    public function index(Request $request): View
    {
        return $this->view('index', ['who' => 'place']);
    }
}
