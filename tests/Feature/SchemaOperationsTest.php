<?php

use Illuminate\Database\Schema\Blueprint;
use Ions\Foundation\Kernel;
use Ions\Support\DB;

beforeEach(fn () => bootFixtureKernel());

test('can create a table with modern column types, insert, query, and drop', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('widgets');
    $schema->create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->boolean('active')->default(false);
        $t->timestamps();
        $t->softDeletes();
    });
    expect($schema->hasTable('widgets'))->toBeTrue()
        ->and($schema->hasColumn('widgets', 'deleted_at'))->toBeTrue();

    DB::connection()->table('widgets')->insert(['name' => 'gadget', 'active' => true]);
    $row = DB::connection()->table('widgets')->where('name', 'gadget')->first();
    expect($row->name)->toBe('gadget');

    $schema->drop('widgets');
    expect($schema->hasTable('widgets'))->toBeFalse();
});
