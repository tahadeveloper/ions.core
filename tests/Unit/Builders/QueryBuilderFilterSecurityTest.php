<?php

/**
 * Security regression tests for QueryBuilder filter allow-listing.
 *
 * Vulnerability: allowFilters(['name']) with $allow_all = true (old default)
 * caused ensureAllFiltersExist() to return early, meaning ANY column in the
 * request was applied to the query — not just allow-listed ones.
 *
 * Fix: flip the default to $allow_all = false so the allow-list is enforced.
 */

use Ions\Builders\QueryBuilder;
use Ions\Exceptions\InvalidFilterQuery;
use Ions\Exceptions\InvalidSortQuery;
use Ions\Foundation\Kernel;
use Ions\Support\Request;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot the fixture kernel once per test-file run and set up the
 * query-builder parameter names + a scratch table so QueryBuilder has
 * something real to operate on.
 */
function bootForFilterTests(): void
{
    Kernel::resetForTesting();
    Kernel::boot(__DIR__ . '/../../fixtures/app');

    // Provide the query-builder parameter names the request parser needs.
    Kernel::config()->set('query-builder.parameters', [
        'filter'  => 'filter',
        'sort'    => 'sort',
        'include' => 'include',
        'append'  => 'append',
        'fields'  => 'fields',
        'limit'   => 'limit',
    ]);
    Kernel::config()->set('query-builder.request_data_source', 'query');

    // Create a scratch SQLite table so QueryBuilder can bind it.
    \Ions\Support\DB::connection()->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('secret')->nullable();
    });
}

/**
 * Build a QueryBuilder for the 'users' table, injecting a custom HTTP request
 * that carries the given query-string parameters.
 *
 * @param  array<string,mixed>  $queryParams  e.g. ['filter' => ['name' => 'alice']]
 */
function makeQB(array $queryParams = []): QueryBuilder
{
    // Build a Symfony Request carrying the desired params, then wrap it in
    // Ions\Support\Request so QueryBuilder::for() accepts it.
    $uri     = '/?' . http_build_query($queryParams);
    $request = Request::create($uri);

    return QueryBuilder::for('users', $request);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeAll(function () {
    bootForFilterTests();
});

// 1) A filter on a column NOT in the allow-list MUST be rejected.
//    Before the fix: silently applied. After the fix: throws InvalidFilterQuery.
test('allowFilters([name]) rejects a request filter on a non-allow-listed column', function () {
    // Request carries filter[secret]=1 — "secret" is NOT allow-listed.
    $qb = makeQB(['filter' => ['secret' => '1']]);

    expect(fn () => $qb->allowFilters(['name']))
        ->toThrow(InvalidFilterQuery::class);
});

// 2) An allow-listed column IS applied; verify no exception and the WHERE
//    clause ends up in the generated SQL.
test('allowFilters([name]) applies a request filter on the allow-listed column', function () {
    $qb = makeQB(['filter' => ['name' => 'alice']]);

    // Should not throw.
    $qb->allowFilters(['name']);

    // Inspect the underlying Illuminate Builder via reflection (protected property).
    $ref   = new ReflectionProperty(QueryBuilder::class, 'query');
    $ref->setAccessible(true);
    $inner = $ref->getValue($qb);

    expect($inner->toSql())->toContain('name');
    expect($inner->getBindings())->toContain('alice');
});

// 3) Explicit opt-out ($allow_all = true) applies any requested filter without
//    throwing — this preserves the escape hatch for callers who genuinely want it.
test('explicit allow-all opt-out applies any requested filter', function () {
    $qb = makeQB(['filter' => ['anything' => '1']]);

    // Passing allow_all = true explicitly must not throw.
    expect(fn () => $qb->allowFilters([], true))->not->toThrow(InvalidFilterQuery::class);
});

// 4) No filters in the request is a no-op under the strict default — no throw.
test('no requested filters is a no-op (no throw) under the strict default', function () {
    $qb = makeQB([]); // no filter param at all

    expect(fn () => $qb->allowFilters(['name']))->not->toThrow(InvalidFilterQuery::class);
});

// 5) Multi-column array form: allowFilters(['name', 'email']) must enforce the
//    full allow-list — a request carrying a non-listed column must throw, and a
//    request carrying a listed column must be applied.
test('allowFilters([name,email]) rejects a request filter on a non-allow-listed column', function () {
    $qb = makeQB(['filter' => ['secret' => '1']]);

    expect(fn () => $qb->allowFilters(['name', 'email']))->toThrow(InvalidFilterQuery::class);
});

test('allowFilters([name,email]) applies a request filter on a listed column', function () {
    $qb = makeQB(['filter' => ['name' => 'carol']]);

    // Should not throw.
    $qb->allowFilters(['name', 'email']);

    $ref   = new ReflectionProperty(QueryBuilder::class, 'query');
    $ref->setAccessible(true);
    $inner = $ref->getValue($qb);

    expect($inner->toSql())->toContain('name');
    expect($inner->getBindings())->toContain('carol');
});

// 6) Regression: the dangerous two-string variadic form allowFilters('name', 'email')
//    previously coerced 'email' → true for $allow_all, silently bypassing the
//    allow-list.  Now the signature is array-only, so passing a string is a
//    TypeError — the injection path is closed hard.
test('the unsafe two-string variadic form is rejected with a TypeError, not a silent bypass', function () {
    $qb = makeQB(['filter' => ['secret' => '1']]);

    expect(fn () => $qb->allowFilters('name', 'email'))->toThrow(\TypeError::class);
});

// ---------------------------------------------------------------------------
// Sort security (defense-in-depth)
// ---------------------------------------------------------------------------
// Sorting is already gated: allowedSorts() validates before applying.
// No sort applied when allowedSorts() is never called.

test('allowedSorts([name]) rejects a request sort on a non-allow-listed column', function () {
    $qb = makeQB(['sort' => 'secret']);

    expect(fn () => $qb->allowedSorts(['name']))->toThrow(InvalidSortQuery::class);
});

test('allowedSorts([name]) accepts a request sort on an allow-listed column', function () {
    $qb = makeQB(['sort' => 'name']);

    expect(fn () => $qb->allowedSorts(['name']))->not->toThrow(InvalidSortQuery::class);
});

test('no sort is applied when allowedSorts() is never called', function () {
    // Build a QB with a sort in the request but never call allowedSorts().
    $qb = makeQB(['sort' => 'secret']);

    // No throw; and since allowedSorts() was never called, the query has no ORDER BY.
    $ref   = new ReflectionProperty(QueryBuilder::class, 'query');
    $ref->setAccessible(true);
    $inner = $ref->getValue($qb);
    expect($inner->toSql())->not->toContain('order by');
});
