<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One action sent from a driver's phone (4 Nov, 15): its id makes a retry on a weak signal harmless. */
class DriverAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
