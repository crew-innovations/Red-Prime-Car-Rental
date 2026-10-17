<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripDate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_available' => 'boolean'];
    }

    public function bookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Guests already booked on this date. */
    public function bookedGuests(?int $ignoreBookingId = null): int
    {
        // Latest saved data even inside a long save transaction (see Availability::latest).
        $query = $this->bookings()->getQuery();

        return (int) \App\Support\Availability::latest($query)
            ->whereIn('status', \App\Enums\BookingStatus::billable())
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->sum('guests');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
