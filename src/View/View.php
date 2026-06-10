<?php

declare(strict_types=1);

namespace Ions\View;

use Ions\Foundation\Kernel;

/**
 * A lazily-rendered view: a normalized Twig template path plus its data.
 *
 * Returned by the view() helper and BaseController::view(); the dispatcher
 * (ControllerDispatcher::normalize() / Kernel::normalizeToResponse()) calls
 * render() when converting the action return into an HTML Response. The
 * shared Twig Environment ('view.env' singleton) is resolved AT RENDER TIME,
 * never at construction — building a View is free and never touches Twig.
 *
 * Deliberately NOT Stringable: rendering is an explicit, fallible step the
 * dispatcher owns (Twig errors must surface as exceptions, not be swallowed
 * inside a string cast).
 */
final class View
{
    /**
     * @param string               $template Normalized template path (e.g. 'users/index.twig', '@admin/users/index.twig').
     * @param array<string, mixed> $data     Variables passed to the template.
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data = [],
    ) {
    }

    /**
     * Render the template through the shared Twig Environment.
     *
     * Resolution order mirrors Ions\Traits\Twig: the container-bound 'view'
     * factory when available (which itself reuses the 'view.env' singleton),
     * a fresh ViewFactory otherwise (isolated scripts/tests).
     *
     * @throws \Twig\Error\Error When the template is missing or fails to render.
     */
    public function render(): string
    {
        return $this->factory()->make()->render($this->template, $this->data);
    }

    private function factory(): ViewFactory
    {
        try {
            $container = Kernel::app();
            if ($container->bound('view')) {
                $factory = $container->get('view');
                if ($factory instanceof ViewFactory) {
                    return $factory;
                }
            }
        } catch (\Throwable) {
            // Container not booted — fall through to a fresh factory.
        }

        return new ViewFactory();
    }
}
