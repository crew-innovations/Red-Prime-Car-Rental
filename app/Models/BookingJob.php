<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The delivery or collection of a car booking for its driver. Created and kept
 * in step with the booking by App\Support\Drivers\DriverJobs; never edited by hand.
 */
class BookingJob extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const TYPES = [
        'delivery' => 'Delivery',
        'collection' => 'Collection',
        'charter' => 'Charter',
        'transfer' => 'Transfer',
    ];

    /** Steps a driver can be allowed to set (Business settings › Drivers). */
    public const DRIVER_STEPS = [
        'acknowledged' => 'Seen / accepted',
        'on_the_way' => 'On the way',
        'arrived' => 'Arrived',
    ];

    public const STATUSES = [
        'assigned' => 'New',
        'acknowledged' => 'Accepted',
        'on_the_way' => 'On the way',
        'arrived' => 'Arrived',
        'waiting' => 'Waiting for office OK',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
        'declined' => 'Declined by the driver', // 4 Nov (15): the office gives it to someone else
    ];

    /** Not work any more for the driver: finished, cancelled or declined. */
    public const CLOSED = ['done', 'cancelled', 'declined'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ends_at' => 'datetime',
            'status_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'assigned_at' => 'datetime',
            'done_at' => 'datetime',
            'approved_at' => 'datetime',
            'inspection' => 'array',
            'declined_at' => 'datetime',
            'started_at' => 'datetime',
            'late_reported_at' => 'datetime',
            'late_minutes' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function damageReports(): HasMany
    {
        return $this->hasMany(DamageReport::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED, true);
    }

    /** Moving or with the customer: the times location may be shared (4 Nov, 15). */
    public function isActive(): bool
    {
        if (in_array($this->status, ['on_the_way', 'arrived', 'waiting'], true)) {
            return true;
        }
        $b = $this->booking;

        return $this->isOpen() && $b?->status === \App\Enums\BookingStatus::InProgress
            && (in_array($this->type, ['charter', 'transfer'], true) || $b->drive_mode === \App\Enums\DriveMode::WithDriver);
    }

    /** Google Maps directions to where the driver goes next. */
    public function navigateUrl(): ?string
    {
        $b = $this->booking;
        if ($this->type === 'transfer' && $b) {
            return \App\Support\Maps\GoogleMaps::directionsLink($b->pickup_location, $b->dropoff_location, collect((array) $b->stops)->pluck('address')->filter()->all());
        }

        return $this->address ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($this->address) : null;
    }

    public function typeLabel(): string
    {
        if ($this->type === 'charter') {
            return $this->role === 'captain' ? 'Charter · captain' : 'Charter · crew';
        }
        $booking = $this->booking;
        if ($booking && $booking->drive_mode === \App\Enums\DriveMode::WithDriver) {
            return $this->type === 'delivery' ? 'Chauffeur start' : 'Chauffeur end';
        }

        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /** Key for the driver screens' translations (type_... in lang/en/driver.php). */
    public function typeKey(): string
    {
        if ($this->type === 'charter') {
            return $this->role === 'captain' ? 'charter_captain' : 'charter_crew';
        }
        if ($this->booking?->drive_mode === \App\Enums\DriveMode::WithDriver && in_array($this->type, ['delivery', 'collection'], true)) {
            return $this->type === 'delivery' ? 'chauffeur_start' : 'chauffeur_end';
        }

        return $this->type;
    }

    public function statusLabel(): string
    {
        if ($this->type === 'collection' && $this->status === 'done' && $this->booking?->status === \App\Enums\BookingStatus::InProgress) {
            return 'Collected, office to close';
        }

        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'assigned' => 'warning',
            'acknowledged', 'on_the_way', 'arrived' => 'info',
            'waiting', 'declined' => 'warning',
            'done' => 'success',
            default => 'gray',
        };
    }
}
