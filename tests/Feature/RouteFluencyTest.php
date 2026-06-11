<?php

/**
 * Phase 10.7 — Route fluency: ->name() / ->where(), Route::redirect/view/
 * fallback, and group name/middleware prefixes on prefix()->group().
 *
 * Routes are registered directly on the shared collection (the App\Booting
 * pattern) and exercised end-to-end through Kernel::handle(); URL generation
 * goes through the route() helper (UrlResolver).
 */

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class FluencyStampMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Fluency-Mw', 'ran');

        return $response;
    }
}

class SecondStampMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Second-Mw', 'ran');

        return $response;
    }
}

beforeEach(fn () => bootFixtureKernel());

// ---------------------------------------------------------------------------
// ->name()
// ---------------------------------------------------------------------------

test('name() re-keys the just-added route so route() resolves its URL', function () {
    Route::get('/fluent/about', fn () => new Response('about'))->name('fluent.about');

    expect(Kernel::RouteCollection()->get('fluent.about'))->not->toBeNull()
        ->and(route('fluent.about'))->toBe('http://localhost/fluent/about');

    $response = Kernel::handle(Request::create('/fluent/about'));
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('about');
});

test('name() removes the auto-generated random name (single key in the collection)', function () {
    $before = array_keys(Kernel::RouteCollection()->all());
    Route::get('/fluent/renamed', fn () => new Response('x'))->name('fluent.renamed');
    $added = array_diff(array_keys(Kernel::RouteCollection()->all()), $before);

    expect(array_values($added))->toBe(['fluent.renamed']);
});

test('name() works after middleware() — chaining order free', function () {
    Route::get('/fluent/chained', fn () => new Response('chained'))
        ->middleware([FluencyStampMiddleware::class])
        ->name('fluent.chained');

    expect(route('fluent.chained'))->toBe('http://localhost/fluent/chained');

    $response = Kernel::handle(Request::create('/fluent/chained'));
    expect($response->headers->get('X-Fluency-Mw'))->toBe('ran');
});

test('4th-arg naming keeps working (BC)', function () {
    Route::get('/fluent/legacy-named', fn () => new Response('legacy'), [], 'fluent.legacy');

    expect(route('fluent.legacy'))->toBe('http://localhost/fluent/legacy-named');
});

// ---------------------------------------------------------------------------
// ->where()
// ---------------------------------------------------------------------------

test('where() constrains a parameter: matching value resolves, non-matching 404s', function () {
    Route::get('/fluent/users/{id}', function (string $id) {
        return new Response('user ' . $id);
    })->where('id', '\d+');

    $ok = Kernel::handle(Request::create('/fluent/users/42'));
    expect($ok->getStatusCode())->toBe(200)
        ->and($ok->getContent())->toBe('user 42');

    expect(Kernel::handle(Request::create('/fluent/users/abc'))->getStatusCode())->toBe(404);
});

test('where() accepts an array of constraints and chains with name()', function () {
    Route::get('/fluent/posts/{year}/{slug}', function (string $year, string $slug) {
        return new Response($year . ':' . $slug);
    })->where(['year' => '\d{4}', 'slug' => '[a-z-]+'])->name('fluent.posts');

    expect(route('fluent.posts', ['year' => '2026', 'slug' => 'hello']))
        ->toBe('http://localhost/fluent/posts/2026/hello');

    expect(Kernel::handle(Request::create('/fluent/posts/2026/hello'))->getContent())->toBe('2026:hello')
        ->and(Kernel::handle(Request::create('/fluent/posts/26/hello'))->getStatusCode())->toBe(404)
        ->and(Kernel::handle(Request::create('/fluent/posts/2026/UPPER'))->getStatusCode())->toBe(404);
});

test('where() with a string param and no regex throws', function () {
    Route::get('/fluent/bad/{x}', fn () => new Response('x'))->where('x');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Route::redirect()
// ---------------------------------------------------------------------------

test('Route::redirect() answers 302 with the Location header by default', function () {
    Route::redirect('/fluent/old', '/fluent/new');

    $response = Kernel::handle(Request::create('/fluent/old'));
    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('/fluent/new');
});

test('Route::redirect() honours a custom status and is name()-chainable', function () {
    Route::redirect('/fluent/moved', '/fluent/new', 301)->name('fluent.moved');

    $response = Kernel::handle(Request::create('/fluent/moved'));
    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('/fluent/new')
        ->and(route('fluent.moved'))->toBe('http://localhost/fluent/moved');
});

// ---------------------------------------------------------------------------
// Route::view()
// ---------------------------------------------------------------------------

test('Route::view() renders the template with its data', function () {
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

    Route::view('/fluent/page', 'pages.index', ['who' => 'fluent']);

    $response = Kernel::handle(Request::create('/fluent/page'));
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('pages: fluent')
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/html');
});

test('Route::view() chains name() and where()', function () {
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

    Route::view('/fluent/viewed', '@admin.users.index', ['who' => 'named'])->name('fluent.viewed');

    expect(route('fluent.viewed'))->toBe('http://localhost/fluent/viewed')
        ->and(Kernel::handle(Request::create('/fluent/viewed'))->getContent())->toBe('admin ns: named');
});

// ---------------------------------------------------------------------------
// Route::fallback()
// ---------------------------------------------------------------------------

test('fallback catches unmatched GETs but real routes still win', function () {
    Route::get('/fluent/real', fn () => new Response('real'));
    Route::fallback(fn () => new Response('fallback!', 200));

    expect(Kernel::handle(Request::create('/no/such/page'))->getContent())->toBe('fallback!')
        ->and(Kernel::handle(Request::create('/no/such/page'))->getStatusCode())->toBe(200)
        ->and(Kernel::handle(Request::create('/fluent/real'))->getContent())->toBe('real')
        ->and(Kernel::handle(Request::create('/ping'))->getContent())->toBe('pong')
        ->and(Kernel::handle(Request::create('/up'))->getStatusCode())->toBe(200);
});

test('without a fallback, unmatched paths still 404 (BC)', function () {
    expect(Kernel::handle(Request::create('/no/such/page'))->getStatusCode())->toBe(404);
});

// ---------------------------------------------------------------------------
// Group name + middleware prefixes
// ---------------------------------------------------------------------------

test('group name prefix + middleware apply to routes registered inside the group', function () {
    Route::prefix('/admin')->name('admin.')->middleware([FluencyStampMiddleware::class])->group(function () {
        Route::get('/users', fn () => new Response('users'))->name('users');
        Route::get('/posts', fn () => new Response('posts'), [], 'posts');
    });

    // name prefix: both fluent and 4th-arg names get 'admin.' prepended.
    expect(route('admin.users'))->toBe('http://localhost/admin/users')
        ->and(route('admin.posts'))->toBe('http://localhost/admin/posts');

    // middleware: merged onto each route in the group.
    $response = Kernel::handle(Request::create('/admin/users'));
    expect($response->getContent())->toBe('users')
        ->and($response->headers->get('X-Fluency-Mw'))->toBe('ran');
});

test('group middleware merges with per-route middleware', function () {
    Route::prefix('/staff')->middleware([FluencyStampMiddleware::class])->group(function () {
        Route::get('/area', fn () => new Response('area'))->middleware([SecondStampMiddleware::class]);
    });

    $response = Kernel::handle(Request::create('/staff/area'));
    expect($response->headers->get('X-Fluency-Mw'))->toBe('ran')
        ->and($response->headers->get('X-Second-Mw'))->toBe('ran');
});

test('group attributes pop after group(): later routes are unaffected', function () {
    Route::prefix('/scoped')->name('scoped.')->middleware([FluencyStampMiddleware::class])->group(function () {
        Route::get('/inside', fn () => new Response('inside'))->name('inside');
    });

    Route::get('/outside', fn () => new Response('outside'))->name('outside');

    expect(route('outside'))->toBe('http://localhost/outside');

    $response = Kernel::handle(Request::create('/outside'));
    expect($response->getContent())->toBe('outside')
        ->and($response->headers->get('X-Fluency-Mw'))->toBeNull();
});

test('plain prefix()->group() keeps working without name/middleware (BC)', function () {
    Route::prefix('/legacy')->group(function () {
        Route::get('/dash', fn () => new Response('dash'));
    });

    expect(Kernel::handle(Request::create('/legacy/dash'))->getContent())->toBe('dash');
});

test('nested groups stack name prefixes and merge middleware', function () {
    Route::prefix('/outer')->name('outer.')->middleware([FluencyStampMiddleware::class])->group(function () {
        Route::prefix('/inner')->name('inner.')->middleware([SecondStampMiddleware::class])->group(function () {
            Route::get('/deep', fn () => new Response('deep'))->name('deep');
        });
    });

    expect(route('outer.inner.deep'))->toBe('http://localhost/outer/inner/deep');

    $response = Kernel::handle(Request::create('/outer/inner/deep'));
    expect($response->headers->get('X-Fluency-Mw'))->toBe('ran')
        ->and($response->headers->get('X-Second-Mw'))->toBe('ran');
});
