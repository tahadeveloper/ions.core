<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

/**
 * Root-level fixture controller for Phase 9.2 view tests.
 *
 * Lives in tests/fixtures/app/http-controllers/ (NOT src/Http/, which
 * MakeGeneratorsTest wipes) via the dedicated IonsFixture\Http\Controllers\
 * PSR-4 mapping — the FQCN must contain Http\Controllers for viewFolder().
 */
class PagesController extends BaseController
{
    /** Root controller: PagesController -> views/pages/. */
    public function index(Request $request): View
    {
        return $this->view('index', ['who' => 'root']);
    }

    /** Plain view() helper with dot notation through the dispatcher. */
    public function dotted(Request $request): View
    {
        return view('pages.index', ['who' => 'dots']);
    }
}
