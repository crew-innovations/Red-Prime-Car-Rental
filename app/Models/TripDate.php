<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripDate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_available' => 'boolean'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
