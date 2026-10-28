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
    ];

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
        return $query->whereNotIn('status', ['done', 'cancelled']);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['done', 'cancelled'], true);
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
            'waiting' => 'warning',
            'done' => 'success',
            default => 'gray',
        };
    }
}
