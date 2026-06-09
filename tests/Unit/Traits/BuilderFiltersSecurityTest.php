<?php

/**
 * Security regression tests for the BuilderFilters allow-list (1.x backport).
 *
 * Vulnerability: allowFilters(['name']) with $allow_all = true (the OLD default)
 * caused ensureAllFiltersExist() to return early, so ANY column present in the
 * request was applied to the query — not just the allow-listed ones. The old
 * variadic/string form allowFilters('name', 'email') also coerced the second
 * string to true for $allow_all, silently bypassing the allow-list.
 *
 * Fix: allowFilters(array $filters = [], bool $allow_all = false) — array-only,
 * enforced by default, with allowAllFilters() as the explicit opt-out.
 *
 * The 1.x QueryBuilder requires a booted Kernel + DB connection to construct, so
 * rather than booting the whole framework we exercise the BuilderFilters trait in
 * isolation against a real Illuminate query Builder backed by an in-memory SQLite
 * connection (via Capsule). The request is a lightweight stub exposing filters().
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Ions\Exceptions\InvalidFilterQuery;
use Ions\Traits\BuilderFilters;

/**
 * Minimal request stub exposing the filters() Collection that BuilderFilters reads.
 */
function filterRequestStub(array $filters): object
{
    return new class ($filters) {
        public function __construct(private array $filters) {}
        public function filters(): Collection
        {
            return collect($this->filters);
        }
    };
}

/**
 * Build an object that uses the BuilderFilters trait, wired to a real Illuminate
 * query Builder over an in-memory SQLite 'users' table and the given request filters.
 */
function makeFilterSubject(array $filters): object
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $schema = $capsule->getConnection()->getSchemaBuilder();
    if (! $schema->hasTable('users')) {
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('secret')->nullable();
        });
    }

    $query = $capsule->getConnection()->table('users');

    return new class ($query, filterRequestStub($filters)) {
        use BuilderFilters;

        public $query;
        public $request;

        public function __construct($query, $request)
        {
            $this->query = $query;
            $this->request = $request;
        }
    };
}

// 1) A filter on a column NOT in the allow-list MUST be rejected.
test('allowFilters([name]) rejects a request filter on a non-allow-listed column', function () {
    $subject = makeFilterSubject(['secret' => '1']);

    expect(fn () => $subject->allowFilters(['name']))
        ->toThrow(InvalidFilterQuery::class);
});

// 2) An allow-listed column IS applied; the WHERE clause ends up in the SQL.
test('allowFilters([name]) applies a request filter on the allow-listed column', function () {
    $subject = makeFilterSubject(['name' => 'alice']);

    $subject->allowFilters(['name']); // must not throw

    expect($subject->query->toSql())->toContain('name');
    expect($subject->query->getBindings())->toContain('alice');
});

// 3) Explicit opt-out applies any requested filter without throwing.
test('explicit allow-all opt-out applies any requested filter', function () {
    $subject = makeFilterSubject(['anything' => '1']);

    expect(fn () => $subject->allowFilters([], true))->not->toThrow(InvalidFilterQuery::class);
    expect($subject->allowFilters([], true))->toBe($subject);
});

// 4) allowAllFilters() convenience opt-out also does not throw.
test('allowAllFilters() applies any requested filter without throwing', function () {
    $subject = makeFilterSubject(['anything' => '1']);

    expect(fn () => $subject->allowAllFilters())->not->toThrow(InvalidFilterQuery::class);
    expect($subject->allowAllFilters())->toBe($subject);
});

// 5) No filters in the request is a no-op under the strict default — no throw.
test('no requested filters is a no-op (no throw) under the strict default', function () {
    $subject = makeFilterSubject([]);

    expect(fn () => $subject->allowFilters(['name']))->not->toThrow(InvalidFilterQuery::class);
    expect($subject->allowFilters(['name']))->toBe($subject);
});

// 6) Multi-column allow-list still enforces a non-listed column.
test('allowFilters([name,email]) rejects a request filter on a non-allow-listed column', function () {
    $subject = makeFilterSubject(['secret' => '1']);

    expect(fn () => $subject->allowFilters(['name', 'email']))->toThrow(InvalidFilterQuery::class);
});

// 7) Regression: the dangerous two-string variadic form previously coerced the
//    second string to true for $allow_all, silently bypassing the allow-list.
//    The signature is now array-only, so passing a string is a TypeError.
test('the unsafe two-string variadic form is rejected with a TypeError, not a silent bypass', function () {
    $subject = makeFilterSubject(['secret' => '1']);

    expect(fn () => $subject->allowFilters('name', 'email'))->toThrow(\TypeError::class);
});
