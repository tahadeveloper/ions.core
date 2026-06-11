<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture Eloquent model overriding getRouteKeyName() — exercised by the
 * route-model-binding tests (10.2): /slugged/{widget} resolves by `slug`
 * instead of the primary key. Table `slugged_widgets` is created inline
 * by the tests.
 */
class SluggedWidget extends Model
{
    protected $table = 'slugged_widgets';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
