<?php

/**
 * Operator-consistency tests for the canonical Ions\Builders\QueryBuilder.
 *
 * The canonical builder's $filterOperators map (in Ions\Traits\BuilderFilters):
 *   eq  → =
 *   ne  → !=
 *   gt  → >
 *   lt  → <
 *   gte → >=
 *   lte → <=
 *   like → like
 *   in  → in  (uses whereIn; value is a comma-separated string, split by QueryBuilderRequest)
 *
 * Request filter syntax: filter[field:operator]=value  (parsed via QueryBuilderRequest::filters())
 *
 * These are characterization + documentation tests proving every operator maps to
 * the correct SQL fragment on the underlying Illuminate Builder.
 *
 * SQL is inspected via reflection on the protected `query` property (same technique
 * used in QueryBuilderFilterSecurityTest).
 */

use Ions\Builders\QueryBuilder;
use Ions\Support\Request;

// ---------------------------------------------------------------------------
// Test-file bootstrap
// ---------------------------------------------------------------------------

function bootForOperatorTests(): void
{
    \Ions\Foundation\Kernel::resetForTesting();
    \Ions\Foundation\Kernel::boot(__DIR__ . '/../../fixtures/app');

    \Ions\Foundation\Kernel::config()->set('query-builder.parameters', [
        'filter'  => 'filter',
        'sort'    => 'sort',
        'include' => 'include',
        'append'  => 'append',
        'fields'  => 'fields',
        'limit'   => 'limit',
    ]);
    \Ions\Foundation\Kernel::config()->set('query-builder.request_data_source', 'query');

    // Create a scratch table for the canonical builder to bind to. dropIfExists
    // first so the suite is portable to a persistent connection (MySQL CI),
    // where tables survive across tests unlike SQLite :memory:.
    $schema = \Ions\Support\DB::connection()->getSchemaBuilder();
    $schema->dropIfExists('items');
    $schema->create('items', function ($table) {
        $table->increments('id');
        $table->integer('age')->nullable();
        $table->string('name')->nullable();
    });
}

// ---------------------------------------------------------------------------
// Helper — build a QueryBuilder with a single filter applied
// ---------------------------------------------------------------------------

/**
 * @param string $filterKey  e.g. "age:gt"
 * @param string $filterVal  e.g. "5"
 */
function makeOpQB(string $filterKey, string $filterVal): QueryBuilder
{
    $uri     = '/?' . http_build_query(['filter' => [$filterKey => $filterVal]]);
    $request = Request::create($uri);

    return QueryBuilder::for('items', $request);
}

/**
 * Extract the underlying Illuminate Builder from the (protected) $query property.
 */
function innerBuilder(QueryBuilder $qb): \Illuminate\Database\Query\Builder
{
    $ref = new ReflectionProperty(QueryBuilder::class, 'query');
    $ref->setAccessible(true);
    return $ref->getValue($qb);
}

// ---------------------------------------------------------------------------
// Bootstrap once for all tests in this file
// ---------------------------------------------------------------------------

beforeAll(function () {
    bootForOperatorTests();
});

// ---------------------------------------------------------------------------
// Dataset: scalar operators (non-in)
// Each entry: [operator alias, expected SQL operator string, test value]
// ---------------------------------------------------------------------------

dataset('scalar_operators', [
    'eq'   => ['eq',   '=',    '5'],
    'ne'   => ['ne',   '!=',   '5'],
    'gt'   => ['gt',   '>',    '5'],
    'lt'   => ['lt',   '<',    '5'],
    'gte'  => ['gte',  '>=',   '5'],
    'lte'  => ['lte',  '<=',   '5'],
    'like' => ['like', 'like', '%alice%'],
]);

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test(
    'scalar filter operator maps to the correct SQL operator',
    function (string $op, string $sqlOp, string $value) {
        $qb = makeOpQB("age:$op", $value);
        $qb->allowFilters(['age']);

        $inner = innerBuilder($qb);
        $sql   = str_replace([chr(34), chr(96)], "", strtolower($inner->toSql()));

        // The WHERE clause must mention the column and the operator.
        expect($sql)->toContain('age')
            ->and($sql)->toContain($sqlOp);

        // The binding must include the raw test value.
        expect($inner->getBindings())->toContain($value);
    }
)->with('scalar_operators');

test('the in operator builds a whereIn clause (SQL contains "in (")', function () {
    // The comma-separated value "1,2,3" is split by QueryBuilderRequest::getFilterValue()
    // into the array ['1', '2', '3'], which is passed to Illuminate's whereIn().
    $qb = makeOpQB('age:in', '1,2,3');
    $qb->allowFilters(['age']);

    $inner = innerBuilder($qb);
    $sql   = str_replace([chr(34), chr(96)], "", strtolower($inner->toSql()));

    // Must contain the column and the IN clause.
    expect($sql)->toContain('age')
        ->and($sql)->toContain('in (');

    // All three values must appear in the bindings.
    $bindings = $inner->getBindings();
    expect($bindings)->toContain('1')
        ->and($bindings)->toContain('2')
        ->and($bindings)->toContain('3');
});

test('unknown operator falls back to eq (=)', function () {
    // An unrecognised operator alias silently defaults to 'eq' / '='.
    $qb = makeOpQB('age:bogus', '99');
    $qb->allowFilters(['age']);

    $inner = innerBuilder($qb);
    $sql   = str_replace([chr(34), chr(96)], "", strtolower($inner->toSql()));

    expect($sql)->toContain('age')
        ->and($sql)->toContain('= ?');
    expect($inner->getBindings())->toContain('99');
});

test('filter without explicit operator defaults to eq (=)', function () {
    // filter[age]=7  — no colon, so no operator specified.
    $uri     = '/?filter[age]=7';
    $request = Request::create($uri);

    $qb    = QueryBuilder::for('items', $request)->allowFilters(['age']);
    $inner = innerBuilder($qb);
    $sql   = str_replace([chr(34), chr(96)], "", strtolower($inner->toSql()));

    expect($sql)->toContain('age')
        ->and($sql)->toContain('= ?');
    expect($inner->getBindings())->toContain('7');
});
