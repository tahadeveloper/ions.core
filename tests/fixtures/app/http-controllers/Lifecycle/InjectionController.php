<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Lifecycle;

use Ions\Support\Request;
use IonsFixture\Services\GreeterContract;
use IonsFixture\Services\StampService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Action method-injection fixture (9.3): mixed parameter order — provider-bound
 * service (constructor), auto-wired service + Request + route placeholders
 * (int-cast `id`, defaulted `slug`) on the action.
 */
class InjectionController
{
    public function __construct(private readonly GreeterContract $greeter)
    {
    }

    public function show(Request $request, StampService $service, int $id, string $slug = 'unset'): Response
    {
        return new Response(sprintf(
            '%s|%s|%s|%d|%s',
            $this->greeter->greet(),
            $service->stamp(),
            get_debug_type($id),
            $id,
            $slug,
        ));
    }
}
