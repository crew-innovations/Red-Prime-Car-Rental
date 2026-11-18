<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 5 Nov (S5): price per litre for one fuel grade in one month. */
class FuelPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'price_per_litre' => 'decimal:2'];
    }
}
