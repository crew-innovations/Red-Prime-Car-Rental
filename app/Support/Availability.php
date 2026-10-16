<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\Readiness;
use App\Enums\YachtStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Trip;
use App\Models\TripDate;
use App\Models\Yacht;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One place for "can this car / yacht / trip take this booking?".
 * Checks run on the server and lock the car / yacht / trip-date row while a
 * database transaction is open, so two people saving at the same moment cannot
 * both get the same car or the last seats.
 */
class Availability
{
    /** Lock the asset row for the rest of the current transaction. */
    public static function lock(string $type, int $id): ?Model
    {
        $class = match ($type) {
            'car' => Car::class,
            'yacht' => Yacht::class,
            'trip' => Trip::class,
            default => null,
        };

        if (! $class) {
            return null;
        }

        $query = $class::withTrashed()->whereKey($id);

        return DB::transactionLevel() > 0 ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * Another holding booking (or, for a yacht, a scheduled trip departure) in
     * the same time window. Returns a message for staff, or null when free.
     */
    public static function assetConflict(string $type, int $id, $start, $end, ?int $ignoreBookingId = null): ?string
    {
        if (! in_array($type, ['car', 'yacht'], true) || ! $start || ! $end) {
            return null;
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);
        $buffer = Rules::bufferMinutes(); // gap kept free between bookings (Business settings)

        self::lock($type, $id);

        $clash = Booking::query()
            ->blocking()
            ->where('bookable_type', $type)
            ->where('bookable_id', $id)
            ->where('start_at', '<', $end->copy()->addMinutes($buffer))
            ->where('end_at', '>', $start->copy()->subMinutes($buffer))
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId))
            ->orderBy('start_at')
            ->first();

        if ($clash) {
            return ($buffer ? 'Already booked (including the '.$buffer.'-minute gap between bookings): ' : 'Already booked for these dates: ').$clash->reference.' ('.$clash->start_at->format('d M H:i').' – '.$clash->end_at->format('d M H:i').').';
        }

        if ($type === 'yacht' && ($departure = self::tripDepartureClash($id, $start, $end))) {
            return 'This yacht runs the trip "'.$departure->trip?->name.'" on '.$departure->date->format('d M').' at '.substr((string) ($departure->start_time ?? $departure->trip?->start_time), 0, 5)
                .'. Close that trip date first, or pick other times.';
        }

        return null;
    }

    /** A scheduled, open trip departure on this yacht that overlaps the window. */
    public static function tripDepartureClash(int $yachtId, Carbon $start, Carbon $end, ?int $ignoreTripDateId = null): ?TripDate
    {
        return TripDate::query()
            ->with('trip')
            ->where('is_available', true)
            ->whereHas('trip', fn (Builder $q) => $q->where('yacht_id', $yachtId))
            ->whereBetween('date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
            ->when($ignoreTripDateId, fn (Builder $q) => $q->whereKeyNot($ignoreTripDateId))
            ->get()
            ->first(function (TripDate $d) use ($start, $end) {
                [$from, $to] = self::departureWindow($d);

                return $from < $end && $to > $start;
            });
    }

    /** Charter bookings on the trip's yacht that overlap a departure. */
    public static function charterClashForDeparture(TripDate $date): ?Booking
    {
        $yachtId = $date->trip?->yacht_id;
        if (! $yachtId) {
            return null;
        }

        [$from, $to] = self::departureWindow($date);

        return Booking::query()->blocking()
            ->where('bookable_type', 'yacht')->where('bookable_id', $yachtId)
            ->where('start_at', '<', $to)->where('end_at', '>', $from)
            ->first();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function departureWindow(TripDate $d): array
    {
        $time = $d->start_time ?? $d->trip?->start_time ?? '00:00';
        $from = Carbon::parse($d->date->toDateString().' '.$time);

        return [$from, $from->copy()->addMinutes($d->trip?->duration_minutes ?: Rules::tripMinutes())];
    }

    /** Car / yacht is in maintenance or switched off. */
    public static function restriction(string $type, int $id): ?string
    {
        $item = match ($type) {
            'car' => Car::find($id),
            'yacht' => Yacht::find($id),
            default => null,
        };

        if (! $item) {
            return null;
        }

        if (in_array($item->status, [CarStatus::Maintenance, CarStatus::Unavailable, YachtStatus::Maintenance, YachtStatus::Unavailable], true)) {
            return $item->name.' is marked '.$item->status->getLabel().($item->readiness_note ? ' ('.$item->readiness_note.')' : '').'.';
        }

        return null;
    }

    /** Not cleaned / inspected yet after the last return. */
    public static function notReady(?Model $item): ?string
    {
        if (! ($item instanceof Car || $item instanceof Yacht) || ! $item->readiness || $item->readiness === Readiness::Ready) {
            return null;
        }

        return $item->name.': '.$item->readiness->getLabel().($item->readiness_note ? ' ('.$item->readiness_note.')' : '').'.';
    }

    /** Seats still free on a trip date (null = no limit set). Locks the date row in a transaction. */
    public static function seatsLeft(TripDate $date, ?int $ignoreBookingId = null): ?int
    {
        if (DB::transactionLevel() > 0) {
            TripDate::whereKey($date->getKey())->lockForUpdate()->first();
        }

        $seats = $date->available_slots ?? $date->trip?->guest_capacity;

        return $seats === null ? null : max(0, (int) $seats - $date->bookedGuests($ignoreBookingId));
    }

    /** The same driver on another holding booking at the same time. */
    public static function driverConflict(int $driverId, $start, $end, ?int $ignoreBookingId = null): ?Booking
    {
        return Booking::query()->blocking()
            ->where('driver_id', $driverId)
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId))
            ->first();
    }

    public static function holds(?BookingStatus $status): bool
    {
        return $status !== null && in_array($status, BookingStatus::blocking(), true);
    }
}
