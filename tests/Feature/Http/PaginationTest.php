<?php

declare(strict_types=1);

use IonsFixture\Models\Widget;
use Ions\Bundles\Path;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Pagination end-to-end through Kernel::handle() (Phase 10.3)
|--------------------------------------------------------------------------
| Resolvers (current page / path / query string) are wired lazily by
| DatabaseProvider::boot() against Kernel::request(). The /items closure
| paginates fixture Widget rows and views/items.twig renders the
| pagination() Twig function.
*/

function createPaginationRows(int $count = 25): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();

    $schema->dropIfExists('widgets');
    $schema->create('widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('sku');
        $t->integer('price');
    });

    for ($i = 1; $i <= $count; $i++) {
        Widget::query()->create(['name' => 'W' . $i, 'sku' => 'sku-' . $i, 'price' => $i]);
    }
}

beforeEach(function () {
    bootFixtureKernel();
    config(['app.twig.source' => Path::root('views')]);
    createPaginationRows();

    Route::get('/items', function () {
        $items = Widget::query()->orderBy('id')->paginate(10);

        return view('items', ['items' => $items]);
    });
});

afterEach(fn () => Kernel::resetForTesting());

test('?page=2 renders the correct slice with prev/next links and the active page', function () {
    $response = Kernel::handle(Request::create('/items?page=2'));
    $body = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('count:10')
        ->and($body)->toContain('<li class="page-item active" aria-current="page"><span class="page-link">2</span></li>')
        ->and($body)->toContain('rel="prev"')
        ->and($body)->toContain('href="http://localhost/items?page=1"')
        ->and($body)->toContain('rel="next"')
        ->and($body)->toContain('href="http://localhost/items?page=3"');
});

test('the default page is 1 with Previous disabled and the last page shown', function () {
    $body = (string) Kernel::handle(Request::create('/items'))->getContent();

    expect($body)->toContain('count:10')
        ->and($body)->toContain('<li class="page-item disabled" aria-disabled="true"><span class="page-link">&laquo;</span></li>')
        ->and($body)->toContain('href="http://localhost/items?page=3"');
});

test('extra query parameters are preserved on every page link', function () {
    $body = (string) Kernel::handle(Request::create('/items?page=2&filter=cheap'))->getContent();

    expect($body)->toContain('href="http://localhost/items?filter=cheap&amp;page=1"')
        ->and($body)->toContain('href="http://localhost/items?filter=cheap&amp;page=3"');
});

test('a non-numeric page parameter falls back to page 1', function () {
    $body = (string) Kernel::handle(Request::create('/items?page=evil'))->getContent();

    expect($body)->toContain('count:10')
        ->and($body)->toContain('<li class="page-item active" aria-current="page"><span class="page-link">1</span></li>');
});

test('a host views/pagination.twig override replaces the default markup', function () {
    $dir = sys_get_temp_dir() . '/ions-pagination-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/items.twig', '{{ pagination(items) }}');
    file_put_contents($dir . '/pagination.twig', 'OVERRIDE total={{ paginator.total }} pages={{ elements|length }}');
    config(['app.twig.source' => $dir]);

    $body = (string) Kernel::handle(Request::create('/items?page=2'))->getContent();

    expect($body)->toContain('OVERRIDE total=25 pages=3');
});
