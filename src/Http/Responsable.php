<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

interface Responsable
{
    public function toResponse(Request $request): Response;
}
