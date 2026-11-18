<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A scheduled date a package runs, with its own seats (and optional price). */
class PackageDeparture extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_available' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Seats for this departure (null = no limit set). */
    public function seats(): ?int
    {
        return $this->capacity ?? $this->package?->default_capacity;
    }

    /** Guests already on confirmed / pending bookings (latest data, even inside a save transaction). */
    public function bookedGuests(?int $ignoreBookingId = null): int
    {
        return (int) \App\Support\Availability::latest($this->bookings()->getQuery())
            ->whereIn('status', \App\Enums\BookingStatus::billable())
            ->notExpired()
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->sum('guests');
    }

    /** Start of the departure (date + time). */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->start_date->toDateString().' '.($this->start_time ?: '09:00'));
    }

    /** End: the end date if set, otherwise start + package length in days. */
    public function endsAt(): Carbon
    {
        if ($this->end_date) {
            return Carbon::parse($this->end_date->toDateString().' '.($this->start_time ?: '18:00'));
        }

        $days = max(1, (int) ($this->package?->duration_days ?: 1));

        return $days > 1 ? $this->startsAt()->addDays($days - 1)->setTime(18, 0) : $this->startsAt()->addHours(8);
    }

    /** Booking still possible: open, in the future and not past the package's booking cut-off. */
    public function isBookable(): bool
    {
        if (! $this->is_available || $this->start_date->isBefore(today())) {
            return false;
        }

        $cutoff = (int) ($this->package?->booking_cutoff_days ?? 0);

        return ! $cutoff || today()->addDays($cutoff)->lessThanOrEqualTo($this->start_date);
    }

    public function label(?int $ignoreBookingId = null): string
    {
        $seats = $this->seats();
        $left = $seats === null ? null : max(0, $seats - $this->bookedGuests($ignoreBookingId));

        return $this->start_date->format('D, d M Y')
            .($this->end_date ? ' → '.$this->end_date->format('d M') : '')
            .($this->start_time ? ' · '.substr($this->start_time, 0, 5) : '')
            .($left !== null ? " · {$left} seats left" : '')
            .($this->is_available ? '' : ' · closed');
    }
}
