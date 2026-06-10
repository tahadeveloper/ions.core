<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Ions\Database\HasIonsFactory;
use IonsFixture\Models\Factories\WidgetFactory;

/**
 * Fixture model proving the static $factory property override: the
 * convention would resolve IonsFixture\Models\Factories\GizmoFactory (which does
 * not exist) but $factory points at WidgetFactory instead.
 */
class Gizmo extends Model
{
    use HasIonsFactory;

    protected static string $factory = WidgetFactory::class;

    protected $table = 'gizmos';

    public $timestamps = false;

    protected $guarded = [];
}
