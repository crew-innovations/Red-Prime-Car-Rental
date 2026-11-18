<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Damage or an unusual issue reported (usually by a driver). Staff review it;
 * any charge is added by staff through the normal return / payment flow.
 */
class DamageReport extends Model implements HasMedia
{
    use Audited, InteractsWithMedia;

    protected $guarded = ['id'];

    public const KINDS = ['damage' => 'Damage / problem', 'accident' => 'Accident']; // 4 Nov (15)

    public const STATUSES = [
        'open' => 'To review',
        'no_charge' => 'Reviewed, no charge',
        'charged' => 'Reviewed, charge added',
    ];

    /**
     * A reviewer approved a charge for this damage: add it to the booking once.
     * Before the return is completed it is filled in on "Receive back" instead.
     */
    public function applyChargeIfReturned(): void
    {
        $booking = $this->booking;
        if ($this->status !== 'charged' || (float) $this->charge_amount <= 0 || $this->applied_at || ! $booking
            || $booking->status !== \App\Enums\BookingStatus::Completed) {
            return;
        }
        $charges = (array) ($booking->charges ?? []);
        $charges['damage'] = round((float) ($charges['damage'] ?? 0) + (float) $this->charge_amount, 2);
        $booking->update(['charges' => $charges, 'extra_charges' => round(array_sum(array_map('floatval', $charges)), 2)]);
        $this->forceFill(['applied_at' => now()])->saveQuietly();
    }

    /** Approved damage charges not yet on the booking. */
    public static function approvedUnapplied(int $bookingId): float
    {
        return round((float) static::query()->where('booking_id', $bookingId)->where('status', 'charged')->whereNull('applied_at')->sum('charge_amount'), 2);
    }

    protected function casts(): array
    {
        return [
            'charge_amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BookingJob::class, 'booking_job_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('private');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(480)->height(320)->nonQueued();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
