<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The company's Salik / Darb account (4 Nov, 3): top-ups are transfers, the trips are the cost. */
class TollAccount extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'low_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(TollAccountMovement::class)->latest('id');
    }

    public function isLow(): bool
    {
        return $this->is_active && $this->low_balance !== null && (float) $this->balance < (float) $this->low_balance;
    }
}
