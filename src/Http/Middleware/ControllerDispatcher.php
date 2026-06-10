<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Container\Container;
use Ions\Foundation\Kernel;
use Ions\Http\Responsable;
use Ions\Support\Request;
use Ions\View\View;
use Symfony\Component\HttpFoundation\Response;

final class ControllerDispatcher
{
    public function __construct(
        private Container $container,
        private string $controller,
        private string $method,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var object $instance */
        $instance = $this->container->make($this->controller);

        if (method_exists($instance, '_initState')) {
            $instance->_initState($request);
        }
        if (method_exists($instance, '_loadInit')) {
            $instance->_loadInit($request);
        }
        if (method_exists($instance, '_loadedState')) {
            $instance->_loadedState($request);
        }

        $result = null;
        if (method_exists($instance, 'callAction')) {
            $result = $instance->callAction($this->method, [$request]);
        } elseif (method_exists($instance, $this->method)) {
            $result = $instance->{$this->method}($request);
        }

        if (method_exists($instance, '_endState')) {
            $instance->_endState($request);
        }

        return $this->normalize($result, $request);
    }

    private function normalize(mixed $result, Request $request): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if ($result instanceof Responsable) {
            return $result->toResponse($request);
        }
        if ($result instanceof View) {
            return self::viewResponse($result);
        }
        return Kernel::response();
    }

    /**
     * Convert an Ions\View\View action return into a 200 HTML Response.
     *
     * The Content-Type is set explicitly (rather than relying on Symfony's
     * prepare()-time default) so tests and middleware see it immediately.
     * Shared with Kernel::normalizeToResponse() for closure routes.
     */
    public static function viewResponse(View $view): Response
    {
        $response = new Response($view->render(), Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
