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
|   3. Eloquent Model hint + name matches a route param → fetched record
|      (routeKeyName; miss → 404 NotFoundHttpException; nullable miss → null)
|   4. other object type-hints       → container->make() (default/null fallback)
|   5. untyped/mixed FIRST parameter → the request (legacy contract)
|   6. declared default value
|   7. nullable                      → null
|   8. otherwise                     → clear InvalidArgumentException
|
| The model-binding tests (10.2) boot a standalone Capsule connection — no
| kernel involved; the resolver stays pure and queries through Eloquent's
| static connection resolver exactly as a host app would.
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

// ---------------------------------------------------------------------------
// Rule 3 — implicit route model binding (10.2)
// ---------------------------------------------------------------------------

describe('route model binding', function () {
    beforeEach(function () {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('sku');
            $t->integer('price');
        });
        $capsule->schema()->create('slugged_widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('slug');
        });
    });

    afterEach(function () {
        \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
    });

    test('a Model type-hint named after a route placeholder receives the fetched record', function () {
        $gear = \IonsFixture\Models\Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

        [$args] = resolveArgs(fn (\IonsFixture\Models\Widget $widget) => null, ['widget' => (string) $gear->id]);

        expect($args[0])->toBeInstanceOf(\IonsFixture\Models\Widget::class)
            ->and($args[0]->exists)->toBeTrue()
            ->and($args[0]->name)->toBe('Gear');
    });

    test('a missing record on a non-nullable Model parameter throws NotFoundHttpException (404)', function () {
        resolveArgs(fn (\IonsFixture\Models\Widget $widget) => null, ['widget' => '999']);
    })->throws(
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        'No query results for model [IonsFixture\Models\Widget] 999',
    );

    test('a missing record on a nullable Model parameter injects null instead of 404', function () {
        [$args] = resolveArgs(fn (?\IonsFixture\Models\Widget $widget) => null, ['widget' => '999']);
        expect($args)->toBe([null]);
    });

    test('getRouteKeyName() overrides resolve by that column instead of the primary key', function () {
        \IonsFixture\Models\SluggedWidget::query()->create(['name' => 'Cog', 'slug' => 'the-cog']);

        [$args] = resolveArgs(fn (\IonsFixture\Models\SluggedWidget $widget) => null, ['widget' => 'the-cog']);

        expect($args[0])->toBeInstanceOf(\IonsFixture\Models\SluggedWidget::class)
            ->and($args[0]->name)->toBe('Cog');
    });

    test('a Model parameter whose name matches no placeholder falls through to the container (new empty model)', function () {
        \IonsFixture\Models\Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

        [$args] = resolveArgs(fn (\IonsFixture\Models\Widget $other) => null, ['widget' => '1']);

        expect($args[0])->toBeInstanceOf(\IonsFixture\Models\Widget::class)
            ->and($args[0]->exists)->toBeFalse();
    });

    test('regression: binding without a connection resolver throws a clear RuntimeException naming the class and the db engine', function () {
        // No 'db' engine booted: Eloquent has no connection resolver. The
        // resolver must throw its own intelligible error instead of letting
        // Illuminate's bare "Call to a member function connection() on null"
        // Error surface (a blank 500 with debug off).
        \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();

        resolveArgs(fn (\IonsFixture\Models\Widget $widget) => null, ['widget' => '1']);
    })->throws(
        RuntimeException::class,
        "Route model binding for [IonsFixture\Models\Widget] requires the 'db' database engine (config app.database_engine).",
    );

    test('regression: scalar placeholders, services and Request hints are untouched by the binding rule', function () {
        $gear = \IonsFixture\Models\Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

        $container = new Container();
        $container->bind(ResolverTestContract::class, ResolverTestService::class);

        [$args, $request] = resolveArgs(
            fn (Request $r, ResolverTestContract $svc, int $id, \IonsFixture\Models\Widget $widget, string $mode = 'view') => null,
            ['id' => '7', 'widget' => (string) $gear->id],
            $container,
        );

        expect($args[0])->toBe($request)
            ->and($args[1])->toBeInstanceOf(ResolverTestService::class)
            ->and($args[2])->toBe(7)
            ->and($args[3])->toBeInstanceOf(\IonsFixture\Models\Widget::class)
            ->and($args[3]->name)->toBe('Gear')
            ->and($args[4])->toBe('view');
    });
});
