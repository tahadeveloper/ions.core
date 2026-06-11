<?php

declare(strict_types=1);

namespace IonsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Ions\Database\HasIonsFactory;

/**
 * Fixture model proving the 11.2 first-choice resolution: Gadget has NO
 * static $factory property and NO conventional IonsFixture\Models\Factories\
 * GadgetFactory, so Gadget::factory() must resolve the top-level
 * Database\Factories\GadgetFactory (Laravel parity).
 */
class Gadget extends Model
{
    use HasIonsFactory;

    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];
}
