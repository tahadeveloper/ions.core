<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Ions\Database\HasIonsFactory;

/**
 * Fixture Eloquent model exercised by the model-factory tests
 * (tests/Feature/Database/FactoryTest.php). Table `widgets` is created
 * inline via Schema::create in the test.
 */
class Widget extends Model
{
    use HasIonsFactory;

    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];
}
