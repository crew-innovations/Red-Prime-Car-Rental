<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saved column mapping per authority / toll type (4 Nov, C.3). */
class ImportMapping extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
