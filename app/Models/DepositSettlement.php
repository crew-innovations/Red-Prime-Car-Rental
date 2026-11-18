<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The deposit settled against what the customer owes (4 Nov, 4.2). A snapshot: never changed later. */
class DepositSettlement extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['held' => 'decimal:2', 'owed' => 'decimal:2', 'applied' => 'decimal:2', 'refunded' => 'decimal:2', 'to_collect' => 'decimal:2', 'lines' => 'array'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
