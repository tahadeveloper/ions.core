<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture child model for Widget->parts() — exercised by the ORM strict-mode
 * tests (tests/Feature/Database/OrmStrictModeTest.php). Table `parts` is
 * created inline in the test.
 */
class Part extends Model
{
    protected $table = 'parts';

    public $timestamps = false;

    protected $guarded = [];
}
