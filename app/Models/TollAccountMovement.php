<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TollAccountMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'date' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TollAccount::class, 'toll_account_id');
    }
}
