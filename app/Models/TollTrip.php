<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Salik / Darb gate pass or parking charge (4 Nov, section 3). */
class TollTrip extends Model
{
    protected $guarded = ['id'];

    public const TYPES = ['salik' => 'Salik', 'darb' => 'Darb', 'parking' => 'Parking'];

    public const STATUSES = [
        'new' => 'Needs review',
        'matched' => 'Matched',
        'charged' => 'Charged to customer',
        'company' => 'Company cost',
        'supplier' => 'Recharged to supplier',
    ];

    public const COLORS = ['new' => 'danger', 'matched' => 'warning', 'charged' => 'info', 'company' => 'gray', 'supplier' => 'gray'];

    protected function casts(): array
    {
        return ['passed_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(BookingCharge::class, 'booking_charge_id');
    }

    public static function dedupeKey(string $type, ?string $tag, ?string $plate, $at, ?string $gate): string
    {
        return mb_substr($type.'|'.mb_strtoupper(preg_replace('/\s+/', '', (string) ($tag ?: $plate))).'|'.\Illuminate\Support\Carbon::parse($at)->format('YmdHis').'|'.mb_strtolower(trim((string) $gate)), 0, 191);
    }
}
