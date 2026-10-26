<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DriveMode;
use App\Enums\Readiness;
use App\Enums\YachtStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\PackageDeparture;
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
    /**
     * Inside a transaction, read the latest saved bookings (not the transaction's
     * older snapshot), so a booking someone else just saved is always seen.
     */
    public static function latest(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->sharedLock() : $query;
    }

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

        $clash = self::latest(Booking::query())
            ->blocking()
            ->where('bookable_type', $type)
            ->where('bookable_id', $id)
            ->where('start_at', '<', $end->copy()->addMinutes($buffer))
            ->where(function (Builder $q) use ($start, $buffer) {
                $from = $start->copy()->subMinutes($buffer);
                $q->where('end_at', '>', $from);
                // Not returned yet (late): still out, so it blocks anything starting before now.
                if (now()->greaterThan($from)) {
                    $q->orWhere('status', BookingStatus::InProgress);
                }
            })
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId))
            ->orderBy('start_at')
            ->first();

        if ($clash && $clash->status === BookingStatus::InProgress && $clash->end_at->lessThanOrEqualTo($start)) {
            return 'Still out on '.$clash->reference.' (was due back '.$clash->end_at->format('d M H:i').'). Receive it first.';
        }

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
        return self::latest(TripDate::query())
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

        return self::latest(Booking::query())->blocking()
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

    /** Another booking of the same car / yacht that has not been returned yet. */
    public static function stillOut(Booking $booking): ?Booking
    {
        if (! in_array($booking->bookable_type, ['car', 'yacht'], true)) {
            return null;
        }

        return Booking::query()
            ->where('bookable_type', $booking->bookable_type)
            ->where('bookable_id', $booking->bookable_id)
            ->where('status', BookingStatus::InProgress)
            ->whereKeyNot($booking->getKey())
            ->first();
    }

    /** Holding bookings from now on for a car / yacht (used to warn when it is taken out of service). */
    public static function upcomingHolds(Model $item): \Illuminate\Support\Collection
    {
        return Booking::query()->blocking()
            ->where('bookable_type', $item->getMorphClass())
            ->where('bookable_id', $item->getKey())
            ->where('end_at', '>', now())
            ->orderBy('start_at')
            ->get();
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

    /** Places still free on a package departure (null = no limit set). Locks the departure row in a transaction. */
    public static function packageSeatsLeft(PackageDeparture $departure, ?int $ignoreBookingId = null): ?int
    {
        if (DB::transactionLevel() > 0) {
            PackageDeparture::whereKey($departure->getKey())->lockForUpdate()->first();
        }

        $seats = $departure->seats();

        return $seats === null ? null : max(0, (int) $seats - $departure->bookedGuests($ignoreBookingId));
    }

    /**
     * The same driver busy on another holding booking. A with-driver booking keeps
     * the driver for the whole time; a self-drive booking only at the delivery
     * (start) and the collection (end). Jobs closer than the gap set in Business
     * settings (minutes) count as a clash, so the driver can get from one to the next.
     */
    public static function driverConflict(int $driverId, $start, $end, ?int $ignoreBookingId = null, DriveMode|string|null $driveMode = DriveMode::WithDriver): ?Booking
    {
        if (! $start) {
            return null;
        }
        $start = Carbon::parse($start);
        $end = $end ? Carbon::parse($end) : $start->copy();
        $gap = max(0, (int) \App\Models\Setting::read('driver_job_gap_minutes', 90));
        $mine = self::driverWindows($start, $end, $driveMode);

        $others = self::latest(Booking::query())->blocking()
            ->where('driver_id', $driverId)
            ->where('service_type', 'car')
            ->where('start_at', '<', $end->copy()->addMinutes($gap))
            ->where('end_at', '>', $start->copy()->subMinutes($gap))
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId))
            ->orderBy('start_at')
            ->get();

        foreach ($others as $other) {
            foreach (self::driverWindows($other->start_at, $other->end_at, $other->drive_mode) as $b) {
                foreach ($mine as $a) {
                    if (self::windowsClash($a, $b, $gap)) {
                        return $other;
                    }
                }
            }
        }

        return null;
    }

    /** [[from, to], …] when the driver is needed. A point (delivery / collection) has from = to. */
    public static function driverWindows($start, $end, DriveMode|string|null $driveMode): array
    {
        $mode = $driveMode instanceof DriveMode ? $driveMode : DriveMode::tryFrom((string) $driveMode);

        return $mode === DriveMode::WithDriver
            ? [[$start, $end]]
            : [[$start, $start], [$end, $end]];
    }

    protected static function windowsClash(array $a, array $b, int $gap): bool
    {
        $aIsSpan = $a[0]->ne($a[1]);
        $bIsSpan = $b[0]->ne($b[1]);

        if ($aIsSpan && $bIsSpan) {
            return $a[0]->lt($b[1]) && $b[0]->lt($a[1]);
        }

        // A point is free only when it is at least $gap minutes away from the other window.
        return $a[0]->copy()->subMinutes($gap)->lt($b[1]) && $b[0]->lt($a[1]->copy()->addMinutes($gap));
    }

    public static function holds(?BookingStatus $status): bool
    {
        return $status !== null && in_array($status, BookingStatus::blocking(), true);
    }
}
