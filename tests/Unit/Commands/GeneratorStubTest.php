<?php

/**
 * Generator stub modernisation tests — Task 4.3 (Phase 4).
 *
 * These tests assert that the code generators under src/commands/ emit
 * Laravel 11-safe Eloquent APIs and do NOT emit the removed/deprecated ones:
 *
 *  - $table->increments('id')  →  $table->id()
 *  - SoftDeletingTrait          →  SoftDeletes
 *  - protected $dates           →  protected $casts (with 'deleted_at' => 'datetime')
 */

// ---------------------------------------------------------------------------
// Stub files — no removed APIs
// ---------------------------------------------------------------------------

test('no generator stub uses removed Eloquent APIs (increments / SoftDeletingTrait / $dates)', function () {
    $stubs = glob(__DIR__ . '/../../../src/commands/**/*.stub') ?: [];
    $stubs = array_merge($stubs, glob(__DIR__ . '/../../../src/commands/stubs/*.stub') ?: []);
    $stubs = array_unique($stubs);

    // At least the known stubs must be present so this test cannot silently pass
    // if the glob returns nothing.
    expect($stubs)->not->toBeEmpty();

    foreach ($stubs as $stub) {
        $code = file_get_contents($stub);

        expect($code)->not->toContain('SoftDeletingTrait');
        expect($code)->not->toContain('protected $dates');
        // Check the method-call form ->increments( to avoid false positives on the word
        expect($code)->not->toMatch('/->\s*increments\s*\(/');
    }
});

// ---------------------------------------------------------------------------
// schema.stub — must use $table->id()
// ---------------------------------------------------------------------------

test('schema.stub uses $table->id() not increments()', function () {
    $stub = __DIR__ . '/../../../src/commands/stubs/schema.stub';
    expect(file_exists($stub))->toBeTrue();

    $code = file_get_contents($stub);
    expect($code)->toContain('->id()');
    expect($code)->not->toMatch('/->\s*increments\s*\(/');
});

// ---------------------------------------------------------------------------
// MigrateCommand.php — must use $table->id()
// ---------------------------------------------------------------------------

test('MigrateCommand emits $table->id() not increments()', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/commands/migrate/MigrateCommand.php');

    expect($src)->not->toMatch('/->\s*increments\s*\(\s*[\'"]id[\'"]\s*\)/');
    expect($src)->toContain('->id()');
});

// ---------------------------------------------------------------------------
// model.stub — modern stub: HasIonsFactory live, no removed Eloquent APIs
// ---------------------------------------------------------------------------

test('model.stub uses HasIonsFactory and no removed Eloquent APIs', function () {
    $stub = file_get_contents(__DIR__ . '/../../../src/commands/stubs/model.stub');

    expect($stub)->toContain('use Ions\\Database\\HasIonsFactory;')
        ->toContain('use HasIonsFactory;')
        ->not->toContain('SoftDeletingTrait')
        ->not->toContain('protected $dates');
});
