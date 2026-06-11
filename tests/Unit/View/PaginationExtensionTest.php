<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Ions\View\PaginationExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Pure rendering tests: paginators are constructed by hand (explicit path /
 * query options), no kernel boot, the Twig env only matters for the host
 * template override.
 */
function paginationEnv(array $templates = []): Environment
{
    $env = new Environment(new ArrayLoader($templates), ['cache' => false]);
    $env->addExtension(new PaginationExtension());

    return $env;
}

function lengthAware(int $total, int $perPage, int $current, array $query = []): LengthAwarePaginator
{
    return new LengthAwarePaginator(
        array_fill(0, min($perPage, $total), 'row'),
        $total,
        $perPage,
        $current,
        ['path' => '/items', 'query' => $query, 'pageName' => 'page'],
    );
}

test('a middle page renders a ±2 window with first/last pages and ellipses', function () {
    $html = (new PaginationExtension())->render(paginationEnv(), lengthAware(200, 10, 10));

    expect($html)->toContain('<nav')
        ->and($html)->toContain('class="pagination"')
        // active current page rendered as a non-link
        ->and($html)->toContain('<li class="page-item active" aria-current="page"><span class="page-link">10</span></li>')
        // window pages current ±2
        ->and($html)->toContain('href="/items?page=8"')
        ->and($html)->toContain('href="/items?page=12"')
        // first/last always present
        ->and($html)->toContain('href="/items?page=1"')
        ->and($html)->toContain('href="/items?page=20"')
        // two gaps
        ->and(substr_count($html, '&hellip;'))->toBe(2)
        // pages outside the window are not linked
        ->and($html)->not->toContain('href="/items?page=6"')
        ->and($html)->not->toContain('href="/items?page=14"');
});

test('the first page disables Previous and links Next', function () {
    $html = (new PaginationExtension())->render(paginationEnv(), lengthAware(50, 10, 1));

    expect($html)->toContain('<li class="page-item disabled" aria-disabled="true"><span class="page-link">&laquo;</span></li>')
        ->and($html)->toContain('rel="next"')
        ->and($html)->toContain('href="/items?page=2"');
});

test('the last page disables Next and links Previous', function () {
    $html = (new PaginationExtension())->render(paginationEnv(), lengthAware(50, 10, 5));

    expect($html)->toContain('<li class="page-item disabled" aria-disabled="true"><span class="page-link">&raquo;</span></li>')
        ->and($html)->toContain('rel="prev"')
        ->and($html)->toContain('href="/items?page=4"');
});

test('a window touching the edges renders no ellipses', function () {
    $html = (new PaginationExtension())->render(paginationEnv(), lengthAware(30, 10, 2));

    expect($html)->not->toContain('&hellip;')
        ->and($html)->toContain('href="/items?page=1"')
        ->and($html)->toContain('href="/items?page=3"');
});

test('existing query parameters are preserved and URLs are escaped', function () {
    $html = (new PaginationExtension())->render(
        paginationEnv(),
        lengthAware(50, 10, 2, ['filter' => 'a b', 'sort' => 'name']),
    );

    expect($html)->toContain('href="/items?filter=a%20b&amp;sort=name&amp;page=1"');
});

test('a single page of results renders nothing', function () {
    expect((new PaginationExtension())->render(paginationEnv(), lengthAware(5, 10, 1)))->toBe('');
});

test('a simple (non length-aware) paginator renders prev/next only', function () {
    // 11 items fetched for perPage 10 => hasMorePages() true.
    $paginator = new Paginator(array_fill(0, 11, 'row'), 10, 2, ['path' => '/items', 'pageName' => 'page']);

    $html = (new PaginationExtension())->render(paginationEnv(), $paginator);

    expect($html)->toContain('rel="prev"')
        ->and($html)->toContain('href="/items?page=1"')
        ->and($html)->toContain('rel="next"')
        ->and($html)->toContain('href="/items?page=3"')
        ->and($html)->not->toContain('page-item active');
});

test('a pagination.twig template in the Twig loader overrides the default markup', function () {
    $env = paginationEnv([
        'pagination.twig' => 'CUSTOM total={{ paginator.total }} current={{ paginator.currentPage }} elements={{ elements|length }}',
    ]);

    $html = (new PaginationExtension())->render($env, lengthAware(50, 10, 3));

    expect($html)->toBe('CUSTOM total=50 current=3 elements=5');
});
