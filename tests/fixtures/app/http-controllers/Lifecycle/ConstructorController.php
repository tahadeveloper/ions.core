<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use Ions\Foundation\BaseController;
use Ions\Support\Request;
use IonsFixture\Services\GreeterContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Constructor-injection fixture on a REAL BaseController subclass: proves the
 * container chain (subclass deps + parent::__construct() wiring) end-to-end.
 */
class ConstructorController extends BaseController
{
    public function __construct(private readonly GreeterContract $greeter)
    {
        parent::__construct();
    }

    public function show(Request $request): Response
    {
        return new Response('ctor:' . $this->greeter->greet());
    }
}
