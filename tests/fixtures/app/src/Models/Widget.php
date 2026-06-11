<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ions\Database\HasIonsFactory;

/**
 * Fixture Eloquent model exercised by the model-factory tests
 * (tests/Feature/Database/FactoryTest.php) and the ORM strict-mode tests
 * (tests/Feature/Database/OrmStrictModeTest.php). Table `widgets` is created
 * inline via Schema::create in the tests.
 */
class Widget extends Model
{
    use HasIonsFactory;

    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Lazy-loading this relation must throw under ORM strict mode (10.6).
     *
     * @return HasMany<Part, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class, 'widget_id');
    }
}
