<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarMaintenance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cost' => 'decimal:2'];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
