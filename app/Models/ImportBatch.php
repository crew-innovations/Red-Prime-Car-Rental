<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One imported statement file (4 Nov, C.3): who, when, how many rows were new, duplicates or errors. */
class ImportBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
