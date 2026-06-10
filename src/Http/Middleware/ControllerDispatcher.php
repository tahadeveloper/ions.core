<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Container\Container;
use Ions\Foundation\Kernel;
use Ions\Http\ActionArgumentResolver;
use Ions\Http\ResponseNormalizer;
use Ions\Support\Request;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipeline terminal for string-controller routes. Full lifecycle (9.3) —
 * every hook is duck-typed by name, so plain controllers need no base class.
 * Legacy underscore hooks use raw method_exists (unchanged); the four new
 * hooks additionally require PUBLIC visibility (see hasPublicHook()):
 *
 *   __construct (container-built: constructor DI)
 *   → _initState → _loadInit → _loadedState            (legacy, unchanged)
 *   → boot()                                           (method-injected, no route params;
 *                                                       skipped when the action itself is named boot)
 *   → [controller middleware() — sub-pipeline]
 *       → beforeAction($request): ?Response            (non-null short-circuits)
 *       → action (method-injected: Request, route placeholders, services)
 *       → normalize (Response | Responsable | View | shared-response fallback)
 *       → afterAction($request, $response): ?Response  (non-null replaces)
 *   → [/controller middleware]
 *   → _endState                                        (always — even on short-circuit)
 *
 * middleware() entries are resolved fail-closed through
 * Kernel::resolveMiddleware() (the 4.1 per-route policy) and run as a
 * sub-pipeline INSIDE this terminal, wrapping only the action phase. The
 * alternative — instantiating the controller earlier and merging into the
 * main pipeline — would move controller construction before the group/route
 * middleware and change lifecycle timing; the sub-pipeline keeps construction
 * and the legacy hooks exactly where they always ran.
 */
final class ControllerDispatcher
{
    private ActionArgumentResolver $resolver;

    /**
     * @param array<string, mixed> $routeParams Route placeholder values by name.
     */
    public function __construct(
        private Container $container,
        private string $controller,
        private string $method,
        private array $routeParams = [],
    ) {
        $this->resolver = new ActionArgumentResolver($container);
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

        // boot(): the "easy boot" hook — method-injected (Request + container
        // services; deliberately NO route params, those belong to the action).
        // When the dispatched ACTION is itself named 'boot' the action wins and
        // the hook is skipped — otherwise the method would fire twice per hit
        // (this preserves the legacy App\Schedule::boot web-cron contract).
        // (method_exists is repeated inline for phpstan's type narrowing.)
        if ($this->method !== 'boot' && method_exists($instance, 'boot') && $this->hasPublicHook($instance, 'boot')) {
            $instance->boot(...$this->resolver->resolve(new ReflectionMethod($instance, 'boot'), $request, []));
        }

        $actionPhase = fn (Request $r): Response => $this->actionPhase($instance, $r);

        $middleware = $this->controllerMiddleware($instance);
        $response = $middleware === []
            ? $actionPhase($request)
            : (new Pipeline($middleware, $actionPhase))->handle($request);

        // _endState always runs — including after a beforeAction short-circuit
        // or a controller-middleware early return.
        if (method_exists($instance, '_endState')) {
            $instance->_endState($request);
        }

        return $response;
    }

    /**
     * The action phase: beforeAction guard → action → normalize → afterAction.
     */
    private function actionPhase(object $instance, Request $request): Response
    {
        if (method_exists($instance, 'beforeAction') && $this->hasPublicHook($instance, 'beforeAction')) {
            $early = $instance->beforeAction($request);
            if ($early instanceof Response) {
                return $early; // Short-circuit: action + afterAction skipped.
            }
        }

        $result = null;
        if (method_exists($instance, 'callAction')) {
            $result = $instance->callAction($this->method, $this->actionArguments($instance, $request));
        } elseif (method_exists($instance, $this->method)) {
            $result = $instance->{$this->method}(...$this->actionArguments($instance, $request));
        }

        $response = ResponseNormalizer::normalize($result, $request);

        // afterAction receives the NORMALIZED response (after View/Responsable
        // conversion); a non-null Response return replaces it.
        if (method_exists($instance, 'afterAction') && $this->hasPublicHook($instance, 'afterAction')) {
            $replacement = $instance->afterAction($request, $response);
            if ($replacement instanceof Response) {
                $response = $replacement;
            }
        }

        return $response;
    }

    /**
     * @return list<mixed>
     */
    private function actionArguments(object $instance, Request $request): array
    {
        if (!method_exists($instance, $this->method)) {
            // Missing method dispatched through callAction()/__call(): keep
            // the exact legacy argument shape (and BadMethodCallException).
            return [$request];
        }

        return $this->resolver->resolve(new ReflectionMethod($instance, $this->method), $request, $this->routeParams);
    }

    /**
     * Read the controller's middleware() hook (when present) and resolve each
     * entry fail-closed via Kernel::resolveMiddleware() — an unresolvable
     * alias/class throws (rendered as a 500), never silently dropped.
     *
     * @return list<MiddlewareInterface>
     */
    private function controllerMiddleware(object $instance): array
    {
        if (!method_exists($instance, 'middleware') || !$this->hasPublicHook($instance, 'middleware')) {
            return [];
        }

        // No (array) cast: it would turn a bare MiddlewareInterface return
        // into its property map (often []) and silently DROP it — fail-open.
        $entries = $instance->middleware();

        $resolved = [];
        foreach (is_array($entries) ? $entries : [$entries] as $entry) {
            $resolved[] = $entry instanceof MiddlewareInterface
                ? $entry
                : Kernel::resolveMiddleware((string) $entry);
        }

        return $resolved;
    }

    /**
     * The four 9.3 hooks (boot, middleware, beforeAction, afterAction) are
     * detected on PUBLIC methods only — method_exists alone also matches
     * protected/private helpers (e.g. a host's protected boot()), which the
     * dispatcher cannot call. Legacy underscore hooks keep raw method_exists.
     */
    private function hasPublicHook(object $instance, string $name): bool
    {
        return method_exists($instance, $name)
            && (new ReflectionMethod($instance, $name))->isPublic();
    }
}
