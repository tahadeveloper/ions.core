<?php

declare(strict_types=1);

namespace IonsFixture\Http\Controllers\Binding;

use IonsFixture\Models\SluggedWidget;
use IonsFixture\Models\Widget;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-model-binding fixture (10.2): Model-typed action parameters named
 * after route placeholders receive the fetched record; a non-matching name
 * keeps the pre-binding container-make behavior (new empty model).
 */
class WidgetsController
{
    public function show(Widget $widget): Response
    {
        return new Response('widget:' . $widget->name);
    }

    public function showNullable(?Widget $widget): Response
    {
        return new Response($widget === null ? 'widget:none' : 'widget:' . $widget->name);
    }

    public function showBySlug(SluggedWidget $widget): Response
    {
        return new Response('slugged:' . $widget->name);
    }

    /** Param name does not match the {widget} placeholder → rule 4 (container make). */
    public function fresh(Widget $other): Response
    {
        return new Response('fresh:' . ($other->exists ? 'persisted' : 'empty'));
    }
}
