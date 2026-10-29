<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The transfer price list for one vehicle category (optionally for one service).
 * Every amount is set by the admin; an empty field is simply not charged.
 */
class TransferRate extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const MONEY = ['base_fee', 'per_km', 'min_fare', 'per_hour', 'half_day_price', 'full_day_price', 'airport_fee', 'per_stop', 'waiting_per_hour', 'toll_fee'];

    protected function casts(): array
    {
        return array_fill_keys(self::MONEY, 'decimal:2') + [
            'min_hours' => 'decimal:2', 'half_day_hours' => 'decimal:2', 'full_day_hours' => 'decimal:2',
            'night_percent' => 'decimal:2', 'return_discount_percent' => 'decimal:2',
            'free_waiting_minutes' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean',
        ];
    }

    /** Fields that make up a price: they can't change once a confirmed booking or a sent quotation uses the rate (29 Oct, 7). */
    public const PRICED = [...self::MONEY, 'min_hours', 'half_day_hours', 'full_day_hours', 'night_percent', 'night_from', 'night_to',
        'return_discount_percent', 'free_waiting_minutes', 'vehicle_category_id', 'transfer_service_id', 'starts_on'];

    protected static function booted(): void
    {
        static::updating(function (TransferRate $r) {
            if ($r->isDirty(self::PRICED) && $r->isUsed()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.per_km' => 'Confirmed bookings or sent quotations use this rate. Give it an end date and add a new rate, so their prices stay as agreed.']);
            }
        });
        static::deleting(function (TransferRate $r) {
            if ($r->isUsed()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.per_km' => 'Confirmed bookings or sent quotations use this rate. Give it an end date instead.']);
            }
        });
    }

    public function isUsed(): bool
    {
        return Booking::withTrashed()->where('price_breakdown->transfer->rate_id', $this->id)->whereIn('status', array_map(fn ($s) => $s->value, \App\Enums\BookingStatus::billable()))->exists()
            || Quotation::withTrashed()->where('price_breakdown->transfer->rate_id', $this->id)->whereIn('status', ['sent', 'negotiation', 'accepted'])->exists();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(TransferService::class, 'transfer_service_id');
    }

    /** The rate in force for this category / service on a date: service-specific first. */
    public static function for(?int $categoryId, ?int $serviceId, ?Carbon $on = null): ?self
    {
        if (! $categoryId) {
            return null;
        }
        $day = ($on ?? now())->toDateString();

        return static::query()->where('vehicle_category_id', $categoryId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('transfer_service_id')->orWhere('transfer_service_id', $serviceId))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $day))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderByRaw('transfer_service_id IS NULL')   // service-specific rate wins
            ->orderByDesc('starts_on')
            ->first();
    }
}
