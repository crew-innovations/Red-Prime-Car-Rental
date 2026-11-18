<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card pre-authorisation held as a deposit (4 Nov, 4.4). Only the provider's reference and
 * the last 4 digits are kept: never the card number, expiry or CVC. It is not money received
 * until it is captured.
 */
class DepositPreauth extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const STATUSES = ['held' => 'Held', 'captured' => 'Captured', 'released' => 'Released', 'expired' => 'Expired'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'captured_amount' => 'decimal:2', 'held_on' => 'date', 'expires_on' => 'date'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }
}
