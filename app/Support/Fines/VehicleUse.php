<?php

namespace App\Support\Fines;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Car;
use Illuminate\Support\Carbon;

/**
 * "Who had this car at this moment?" (4 Nov, C.4), for fines and tolls. Uses the real handover and
 * return times (or the booked end while the car is still out), then the drivers' delivery and
 * collection drives. Swaps (1.5) and hired-in periods (6.3) are added in later phases.
 *
 * @phpstan-type Result array{kind: 'customer'|'driver'|'none', booking: ?Booking, driver_id: ?int, note: string}
 */
class VehicleUse
{
    /** @return array{kind: string, booking: ?Booking, driver_id: ?int, note: string} */
    public static function at(Car $car, Carbon $at): array
    {
        // 19 Nov: a car replaced during a rental contract was with that customer until the swap.
        $swap = \App\Models\ContractVehicleSwap::query()->where('from_car_id', $car->id)->where('swapped_at', '>=', $at)
            ->whereHas('contract.booking', fn ($q) => $q->whereRaw('COALESCE(delivered_at, start_at) <= ?', [$at]))
            ->with('contract.booking')->orderBy('swapped_at')->first();
        if ($swap && ($sb = $swap->contract?->booking) && ! \App\Models\ContractVehicleSwap::query()->where('rental_contract_id', $swap->rental_contract_id)
            ->where('to_car_id', $car->id)->where('swapped_at', '>', $at)->exists()) {
            return ['kind' => $sb->drive_mode === DriveMode::WithDriver ? 'driver' : 'customer', 'booking' => $sb, 'driver_id' => $sb->drive_mode === DriveMode::WithDriver ? $sb->driver_id : null,
                'note' => 'With the customer on '.$sb->reference.' until the car was replaced ('.$swap->swapped_at->format('d M H:i').').'];
        }

        $b = Booking::query()->where('bookable_type', $car->getMorphClass())->where('bookable_id', $car->id)
            ->whereIn('status', [BookingStatus::InProgress, BookingStatus::Completed])
            ->whereRaw('COALESCE(delivered_at, start_at) <= ?', [$at])
            ->where(fn ($q) => $q->where('returned_at', '>=', $at)
                ->orWhere(fn ($w) => $w->whereNull('returned_at')->where('status', BookingStatus::InProgress))
                ->orWhere(fn ($w) => $w->whereNull('returned_at')->where('end_at', '>=', $at)))
            ->orderByDesc('start_at')->first();

        // 19 Nov: this car only joined that rental at the swap; before it, it was not with this customer.
        if ($b && \App\Models\ContractVehicleSwap::query()->where('to_car_id', $car->id)->where('swapped_at', '>', $at)
            ->whereHas('contract', fn ($q) => $q->where('booking_id', $b->id))->exists()) {
            $b = null;
        }

        if ($b) {
            if ($b->drive_mode === DriveMode::WithDriver) {
                return ['kind' => 'driver', 'booking' => $b, 'driver_id' => $b->driver_id, 'note' => 'Chauffeur booking '.$b->reference.': our driver was driving.'];
            }

            return ['kind' => 'customer', 'booking' => $b, 'driver_id' => null,
                'note' => 'With the customer on '.$b->reference.' ('.($b->delivered_at ?? $b->start_at)->format('d M H:i').' – '.($b->returned_at ? $b->returned_at->format('d M H:i') : 'still out').').'];
        }

        // Our driver taking the car to the customer, or bringing it back.
        $job = BookingJob::query()->whereHas('booking', fn ($q) => $q->where('bookable_type', $car->getMorphClass())->where('bookable_id', $car->id))
            ->where(function ($q) use ($at) {
                $q->where(fn ($w) => $w->where('type', 'delivery')->whereNotNull('started_at')->where('started_at', '<=', $at)
                    ->whereHas('booking', fn ($bq) => $bq->where(fn ($x) => $x->whereNull('delivered_at')->orWhere('delivered_at', '>=', $at))))
                    ->orWhere(fn ($w) => $w->where('type', 'collection')->whereNotNull('done_at')->where('done_at', '<=', $at)
                        ->where('done_at', '>=', $at->copy()->subHours(3)));
            })->with('booking')->latest('id')->first();
        if ($job) {
            return ['kind' => 'driver', 'booking' => $job->booking, 'driver_id' => $job->driver_id, 'note' => 'Our driver on the '.$job->type.' of '.$job->booking?->reference.'.'];
        }

        return ['kind' => 'none', 'booking' => null, 'driver_id' => null, 'note' => 'No booking or driver job at that time.'];
    }

    /** A car from a plate (any spacing / case) or a toll tag. */
    public static function car(?string $plate, ?string $tag = null): ?Car
    {
        $norm = fn ($v) => mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        if (filled($tag) && ($car = Car::withTrashed()->whereRaw("UPPER(REPLACE(REPLACE(toll_tag_no, ' ', ''), '-', '')) = ?", [$norm($tag)])->first())) {
            return $car;
        }
        if (blank($plate) || $norm($plate) === '') {
            return null;
        }
        $p = $norm($plate);

        return Car::withTrashed()->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(registration_number, ' ', ''), '-', ''), '/', '')) = ?", [$p])->first()
            // "Dubai A 12345" vs "A12345": the registration ends with the plate
            ?? Car::withTrashed()->whereNotNull('registration_number')->get()->first(fn (Car $c) => ($r = $norm($c->registration_number)) !== '' && (str_ends_with($p, $r) || str_ends_with($r, $p)) && min(strlen($r), strlen($p)) >= 4);
    }
}
