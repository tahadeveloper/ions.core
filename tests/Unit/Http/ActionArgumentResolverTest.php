<?php

declare(strict_types=1);

use Ions\Container\Container;
use Ions\Http\ActionArgumentResolver;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| ActionArgumentResolver matrix (9.3) — pure unit tests, no kernel boot.
|--------------------------------------------------------------------------
| Resolution order per parameter:
|   1. Request-compatible type-hint  → the current request
|   2. name matches a route param    → scalar value (int/float/bool cast)
|   3. other object type-hints       → container->make() (default/null fallback)
|   4. untyped/mixed FIRST parameter → the request (legacy contract)
|   5. declared default value
|   6. nullable                      → null
|   7. otherwise                     → clear InvalidArgumentException
*/

interface ResolverTestContract
{
    public function tag(): string;
}

final class ResolverTestService implements ResolverTestContract
{
    public function tag(): string
    {
        return 'svc';
    }
}

function resolveArgs(Closure $action, array $routeParams = [], ?Container $container = null, ?Request $request = null): array
{
    $container ??= new Container();
    $request ??= Request::create('/unit');

    return [
        (new ActionArgumentResolver($container))->resolve(new ReflectionFunction($action), $request, $routeParams),
        $request,
    ];
}

test('a Request type-hint receives the current request (Ions, Illuminate and Symfony hints)', function () {
    [$args, $request] = resolveArgs(fn (Request $r) => null);
    expect($args)->toBe([$request]);

    [$args, $request] = resolveArgs(fn (\Illuminate\Http\Request $r) => null);
    expect($args)->toBe([$request]);

    [$args, $request] = resolveArgs(fn (\Symfony\Component\HttpFoundation\Request $r) => null);
    expect($args)->toBe([$request]);
});

test('a parameter named after a route placeholder receives the raw value when untyped or string-hinted', function () {
    [$args] = resolveArgs(fn ($slug) => null, ['slug' => 'news']);
    expect($args)->toBe(['news']);

    [$args] = resolveArgs(fn (string $slug) => null, ['slug' => 'news']);
    expect($args)->toBe(['news']);
});

test('int and float type-hints cast numeric route values; non-numeric values pass through raw', function () {
    [$args] = resolveArgs(fn (int $id) => null, ['id' => '42']);
    expect($args)->toBe([42]);

    [$args] = resolveArgs(fn (float $price) => null, ['price' => '9.5']);
    expect($args)->toBe([9.5]);

    // Non-numeric: passed through untouched (PHP raises the type error at call time).
    [$args] = resolveArgs(fn (int $id) => null, ['id' => 'abc']);
    expect($args)->toBe(['abc']);
});

test('bool type-hints cast boolish route values', function () {
    [$args] = resolveArgs(fn (bool $active) => null, ['active' => 'true']);
    expect($args)->toBe([true]);

    [$args] = resolveArgs(fn (bool $active) => null, ['active' => '0']);
    expect($args)->toBe([false]);
});

test('object type-hints resolve through the container (bound interface and auto-wired concrete)', function () {
    $container = new Container();
    $container->bind(ResolverTestContract::class, ResolverTestService::class);

    [$args] = resolveArgs(fn (ResolverTestContract $svc) => null, [], $container);
    expect($args[0])->toBeInstanceOf(ResolverTestService::class);

    [$args] = resolveArgs(fn (ResolverTestService $svc) => null);
    expect($args[0])->toBeInstanceOf(ResolverTestService::class);
});

test('an object type-hint that matches a route param name still resolves from the container', function () {
    [$args] = resolveArgs(fn (ResolverTestService $id) => null, ['id' => '42']);
    expect($args[0])->toBeInstanceOf(ResolverTestService::class);
});

test('an unresolvable object dependency surfaces the container error (no default, not nullable)', function () {
    resolveArgs(fn (ResolverTestContract $svc) => null);
})->throws(\Illuminate\Contracts\Container\BindingResolutionException::class);

test('an unresolvable object dependency falls back to its default, then to null when nullable', function () {
    [$args] = resolveArgs(fn (?ResolverTestContract $svc = null) => null);
    expect($args)->toBe([null]);

    [$args] = resolveArgs(fn (?ResolverTestContract $svc) => null);
    expect($args)->toBe([null]);
});

test('scalar parameters fall back to their declared default when no route param matches', function () {
    [$args] = resolveArgs(fn (string $slug = 'home') => null);
    expect($args)->toBe(['home']);
});

test('an untyped or mixed FIRST parameter keeps the legacy contract: it receives the request', function () {
    [$args, $request] = resolveArgs(fn ($whatever) => null);
    expect($args)->toBe([$request]);

    [$args, $request] = resolveArgs(fn (mixed $whatever) => null);
    expect($args)->toBe([$request]);
});

test('a route param match beats the legacy first-parameter request rule', function () {
    [$args] = resolveArgs(fn ($id) => null, ['id' => '7']);
    expect($args)->toBe(['7']);
});

test('mixed parameter order resolves each parameter independently', function () {
    $container = new Container();
    $container->bind(ResolverTestContract::class, ResolverTestService::class);

    [$args, $request] = resolveArgs(
        fn (ResolverTestContract $svc, int $id, Request $r, string $mode = 'view') => null,
        ['id' => '42'],
        $container,
    );

    expect($args[0])->toBeInstanceOf(ResolverTestService::class)
        ->and($args[1])->toBe(42)
        ->and($args[2])->toBe($request)
        ->and($args[3])->toBe('view');
});

test('an unresolvable non-first scalar parameter throws a clear error naming the parameter', function () {
    resolveArgs(fn (Request $r, int $missing) => null);
})->throws(InvalidArgumentException::class, '$missing');

test('variadic parameters stop argument resolution', function () {
    [$args, $request] = resolveArgs(fn (Request $r, ...$rest) => null);
    expect($args)->toBe([$request]);
});
