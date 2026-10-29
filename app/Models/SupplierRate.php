<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a supplier charges RED PRIME (29 Oct, 11). A rate for one vehicle wins over the
 * supplier's default. Once a booking used a rate, its amount, method and dates can't
 * change: end it and add a new one, so earlier bookings keep their cost.
 */
class SupplierRate extends Model
{
    use Audited, SoftDeletes;

    protected $guarded = ['id'];

    public const METHODS = [
        'per_booking' => 'Fixed per booking', 'per_day' => 'Per day', 'per_week' => 'Per week', 'per_month' => 'Per month',
        'per_km' => 'Per km', 'percent' => '% of booking revenue',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (SupplierRate $r) => $r->created_by ??= auth()->id());
        static::saving(function (SupplierRate $r) {
            if (! array_key_exists($r->method, self::METHODS)) {
                throw ValidationException::withMessages(['data.method' => 'Choose how the supplier charges.']);
            }
            if ((float) $r->amount <= 0 || ($r->method === 'percent' && (float) $r->amount > 100)) {
                throw ValidationException::withMessages(['data.amount' => $r->method === 'percent' ? 'Enter a percentage between 0 and 100.' : 'Enter an amount above zero.']);
            }
            if ($r->starts_on && $r->ends_on && $r->ends_on->lt($r->starts_on)) {
                throw ValidationException::withMessages(['data.ends_on' => 'The end date must be after the start date.']);
            }
            if (($r->vehicle_type === null) !== ($r->vehicle_id === null)) {
                throw ValidationException::withMessages(['data.vehicle_id' => 'Pick the vehicle, or leave both empty for the supplier\'s default.']);
            }
            if ($r->vehicle_id && ($v = $r->vehicleModel()) && (int) $v->supplier_id !== (int) $r->supplier_id) {
                throw ValidationException::withMessages(['data.vehicle_id' => $v->name.' does not belong to this supplier.']);
            }
            if ($r->exists && $r->isDirty(['amount', 'method', 'partial', 'starts_on', 'vehicle_type', 'vehicle_id', 'supplier_id']) && $r->isUsed()) {
                throw ValidationException::withMessages(['data.amount' => 'Bookings already use this rate. Set an end date on it and add a new rate instead.']);
            }
            // Two active rates for the same vehicle (or default) may not overlap: the cost must be unambiguous.
            if ($r->is_active ?? true) {
                $s1 = $r->starts_on?->toDateString();
                $e1 = $r->ends_on?->toDateString();
                $overlap = static::query()->where('supplier_id', $r->supplier_id)->where('is_active', true)->whereKeyNot($r->id ?? 0)
                    ->where('vehicle_type', $r->vehicle_type)->where('vehicle_id', $r->vehicle_id)
                    ->when($s1, fn ($q) => $q->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $s1)))
                    ->when($e1, fn ($q) => $q->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $e1)))
                    ->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['data.starts_on' => 'Another active rate covers these dates. Give the old one an end date first.']);
                }
            }
        });
        static::deleting(function (SupplierRate $r) {
            if ($r->isUsed()) {
                throw ValidationException::withMessages(['rate' => 'Bookings already use this rate. Switch it off or end it instead.']);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function vehicle(): MorphTo
    {
        return $this->morphTo('vehicle', 'vehicle_type', 'vehicle_id');
    }

    public function vehicleModel(): Car|Yacht|null
    {
        return match ($this->vehicle_type) {
            'car' => Car::withTrashed()->find($this->vehicle_id),
            'yacht' => Yacht::withTrashed()->find($this->vehicle_id),
            default => null,
        };
    }

    public function isUsed(): bool
    {
        return $this->exists && Booking::withTrashed()->where('supplier_cost_snapshot->rate_id', $this->id)->exists();
    }

    public function appliesOn(Carbon $day): bool
    {
        return $this->is_active && (! $this->starts_on || $this->starts_on->lte($day)) && (! $this->ends_on || $this->ends_on->gte($day->copy()->startOfDay()));
    }

    public function label(): string
    {
        $amount = $this->method === 'percent' ? rtrim(rtrim(number_format((float) $this->amount, 2), '0'), '.').'%' : 'AED '.number_format((float) $this->amount, 2);

        return $amount.' · '.self::METHODS[$this->method];
    }
}
