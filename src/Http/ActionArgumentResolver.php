<?php

declare(strict_types=1);

namespace Ions\Http;

use InvalidArgumentException;
use Ions\Container\Container;
use Ions\Support\Request;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Resolves the argument list for a controller action, closure route, or the
 * boot() lifecycle hook (Phase 9.3 method injection).
 *
 * Resolution order, per parameter:
 *
 *   1. Request-compatible type-hint (Ions/Illuminate/Symfony Request, or any
 *      class the current request instance satisfies) → the current request.
 *   2. Parameter NAME matches a route placeholder and the parameter is
 *      untyped or scalar-hinted → the route value. Numeric strings are cast
 *      for int/float hints and boolish strings ('1'/'0'/'true'/'false') for
 *      bool hints; anything else passes through raw (PHP raises the type
 *      error at call time — strict_types in the dispatcher means no implicit
 *      coercion).
 *   3. Other object type-hints → Container::make(). When the container cannot
 *      resolve, fall back to the declared default, then null when nullable;
 *      otherwise the container's exception surfaces (rendered as a 500).
 *   4. Untyped (or `mixed`) FIRST parameter → the current request. This is
 *      the legacy contract: actions were previously always invoked with
 *      exactly [$request], so position 0 keeps receiving it.
 *   5. Declared default value.
 *   6. Nullable parameter → null.
 *   7. Otherwise → InvalidArgumentException naming the parameter (a 500 via
 *      the exception handler — never silently mis-invoked).
 *
 * Variadic parameters stop resolution (nothing is spread into them).
 */
final class ActionArgumentResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, mixed> $routeParams Route placeholder values by name.
     * @return list<mixed>
     */
    public function resolve(ReflectionFunctionAbstract $action, Request $request, array $routeParams): array
    {
        $arguments = [];

        foreach ($action->getParameters() as $position => $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $position, $request, $routeParams);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function resolveParameter(ReflectionParameter $parameter, int $position, Request $request, array $routeParams): mixed
    {
        $type = $parameter->getType();
        $named = $type instanceof ReflectionNamedType ? $type : null;

        // (1) The current request for any compatible class hint.
        if ($named !== null && !$named->isBuiltin() && is_a($request, $named->getName())) {
            return $request;
        }

        // (2) Route placeholder by parameter name (scalar/untyped/union only —
        //     object hints belong to the container).
        $name = $parameter->getName();
        if (($named === null || $named->isBuiltin()) && array_key_exists($name, $routeParams)) {
            return $this->castRouteValue($routeParams[$name], $named);
        }

        // (3) Remaining object type-hints come from the container.
        if ($named !== null && !$named->isBuiltin()) {
            try {
                return $this->container->make($named->getName());
            } catch (Throwable $e) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }
                if ($named->allowsNull()) {
                    return null;
                }

                throw $e; // The container's message is the clearest diagnostic.
            }
        }

        // (4) Legacy contract: an untyped (or mixed) first parameter receives
        //     the request — actions were always called with [$request] pre-9.3.
        if ($position === 0 && ($type === null || $named?->getName() === 'mixed')) {
            return $request;
        }

        // (5) Declared default.
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // (6) Nullable.
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new InvalidArgumentException(sprintf(
            'Unable to resolve argument $%s of %s: no matching route placeholder, container binding, or default value.',
            $name,
            $this->describe($parameter),
        ));
    }

    /**
     * Cast a route placeholder value for scalar type-hints. Route values are
     * matcher strings; with strict_types at the call site PHP will not coerce
     * them, so int/float/bool hints get an explicit, conservative cast.
     */
    private function castRouteValue(mixed $value, ?ReflectionNamedType $type): mixed
    {
        if ($type === null || !is_string($value)) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => match (strtolower($value)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => $value,
            },
            default => $value,
        };
    }

    private function describe(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();

        if ($function instanceof ReflectionMethod) {
            return $function->getDeclaringClass()->getName() . '::' . $function->getName() . '()';
        }

        return $function->getName() . '()';
    }
}
