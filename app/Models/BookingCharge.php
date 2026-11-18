<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A charge added to a booking after it was priced (4 Nov, C.1): a fine, tolls, an admin fee.
 * Never deleted: a wrong line is voided with a reason. Pass-through lines (fines, tolls) are
 * collected for the authority and do not count as revenue.
 */
class BookingCharge extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const TYPES = [
        'fine' => 'Traffic fine',
        'admin_fee' => 'Fine admin fee',
        'salik' => 'Salik',
        'darb' => 'Darb',
        'parking' => 'Parking',
        'toll_fee' => 'Toll service fee',
        'damage' => 'Damage',
        'other' => 'Other charge',
    ];

    public const PASS_THROUGH_TYPES = ['fine', 'salik', 'darb', 'parking'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_amount' => 'decimal:2', 'amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('voided_at');
    }

    public function isPassThrough(): bool
    {
        return $this->nature === 'pass_through';
    }

    /** "Group" for invoices and statements (7.2): rent / charges / fines / tolls. */
    public function group(): string
    {
        return match ($this->type) {
            'fine', 'admin_fee' => 'Fines',
            'salik', 'darb', 'parking', 'toll_fee' => 'Tolls',
            default => 'Charges',
        };
    }
}
