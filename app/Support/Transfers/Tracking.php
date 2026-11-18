<?php

namespace App\Support\Transfers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingJob;
use App\Models\Car;
use App\Models\DriverLocation;
use App\Models\Setting;
use App\Models\TransferRate;
use App\Support\Documents\CompanyProfile;
use App\Support\Maps\GoogleMaps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * 6 Nov (limousine L3): the customer follows their chauffeur.
 *
 * - The driver's phone sends its location only while a limousine trip is on: from "On my way"
 *   to "Dropped off" (decision H-48; setting limo_location_during_trips). Never outside a trip.
 * - The customer gets a signed link (no login) that stops working 3 hours after the trip ends.
 * - The page shows the trip step, the car on the map with an ETA, the driver's first name,
 *   the car and plate, and a phone number: the driver's while the trip is on, the office's
 *   before and after (decision H-47).
 */
class Tracking
{
    public const STEPS = ['requested' => 'Request received', 'price' => 'Price confirmed', 'booked' => 'Booked', 'assigned' => 'Driver assigned', 'on_the_way' => 'Driver on the way', 'arrived' => 'Driver has arrived', 'on_board' => 'On board', 'done' => 'Arrived at destination'];

    public static function job(Booking $b): ?BookingJob
    {
        return $b->jobs()->where('type', 'transfer')->whereNotIn('status', ['cancelled', 'declined'])->latest('id')->first();
    }

    /** The phone shares its location now (limousine trip on, or the general setting is on). */
    public static function sharesLocation(BookingJob $job): bool
    {
        if (! $job->isActive()) {
            return false;
        }
        if ((bool) Setting::read('driver_location_enabled', false)) {
            return true;
        }

        return $job->type === 'transfer' && (bool) Setting::read('limo_location_during_trips', true);
    }

    public static function link(Booking $b): string
    {
        $until = Carbon::parse($b->end_at ?? $b->start_at ?? now())->addHours(3);
        $until = $until->lt(now()->addHours(2)) ? now()->addHours(2) : $until;

        return URL::temporarySignedRoute('track.show', $until, ['booking' => $b->id]);
    }

    public static function step(Booking $b, ?BookingJob $job): string
    {
        // 11 Nov: before the trip the page follows the booking: request → price confirmed (pay) → booked.
        if ($b->status === BookingStatus::Cancelled || $b->status === BookingStatus::NoShow) {
            return 'cancelled';
        }
        if ($b->status === BookingStatus::Inquiry || $b->status === BookingStatus::Quotation) {
            return 'requested';
        }
        if ($b->status === BookingStatus::PendingPayment) {
            return 'price';
        }

        return match (true) {
            ! $job => 'booked',
            $job->status === 'done' || $b->returned_at !== null => 'done',
            $b->delivered_at !== null => 'on_board',
            $job->status === 'arrived' => 'arrived',
            $job->status === 'on_the_way' => 'on_the_way',
            (bool) $job->driver_id => 'assigned',
            default => 'booked',
        };
    }

    /** Free waiting: airport pickups get the airport time (setting), others the rate's. */
    public static function freeMinutes(Booking $b): int
    {
        if ($b->transferService?->is_airport) {
            return (int) Setting::read('limo_airport_free_wait', 60);
        }
        $id = rescue(fn () => TransferJourney::pinnedRate($b), null, false);
        $rate = $id ? TransferRate::withTrashed()->find($id) : null;

        return (int) ($rate->free_waiting_minutes ?? 15);
    }

    /** Minutes the driver waited past the free time (from arrival or the pickup time, whichever is later). */
    public static function extraWaiting(Booking $b, BookingJob $job, ?Carbon $pickedUpAt = null): int
    {
        $arrived = isset($job->inspection['arrived_at']) ? Carbon::parse($job->inspection['arrived_at']) : null;
        if (! $arrived) {
            return 0;
        }
        $from = $arrived->max(Carbon::parse($b->start_at));
        $waited = (int) $from->diffInMinutes($pickedUpAt ?? now(), false);

        return max(0, $waited - self::freeMinutes($b));
    }

    /** The money part of the ride page: an estimate while requested, the confirmed price after. */
    public static function price(Booking $b, string $step): array
    {
        $lines = $b->chargeLines()->whereNull('voided_at')->get(['label', 'amount'])->map(fn ($l) => ['label' => $l->label, 'amount' => (float) $l->amount])->all();
        $total = round((float) $b->total + (float) $b->charge_lines_total, 2);

        return [
            'estimate' => $step === 'requested',
            'ride' => (float) $b->total,
            'extras' => $lines,
            'total' => $total,
            'paid' => (float) $b->paid_amount,
            'due' => $step === 'price' ? RideFlow::due($b) : 0.0,
            'can_pay' => $step === 'price' && RideFlow::canPayOnline($b),
            'km' => $b->route_km ? (float) $b->route_km : null,
            'minutes' => $b->route_minutes ? (int) $b->route_minutes : null,
        ];
    }

    /** What the tracking page shows (also polled as JSON). */
    public static function data(Booking $b): array
    {
        $job = self::job($b);
        $step = self::step($b, $job);
        $driver = $job?->driver;
        $car = $b->bookable instanceof Car ? $b->bookable : null;
        $live = in_array($step, ['on_the_way', 'arrived', 'on_board'], true);
        $company = CompanyProfile::values();

        $loc = null;
        if ($live && $driver) {
            $l = DriverLocation::query()->where('user_id', $driver->id)->where('recorded_at', '>=', now()->subMinutes(10))->latest('recorded_at')->first();
            $loc = $l ? ['lat' => (float) $l->lat, 'lng' => (float) $l->lng, 'at' => $l->recorded_at->toIso8601String()] : null;
        }
        $target = $step === 'on_board' ? ($b->dropoff_location ?: null) : ($b->pickup_location ?: null);
        $eta = null;
        if ($loc && $target && in_array($step, ['on_the_way', 'on_board'], true)) {
            $key = 'track:eta:'.$b->id.':'.$step.':'.round($loc['lat'], 3).','.round($loc['lng'], 3);
            $eta = Cache::remember($key, now()->addMinute(), fn () => rescue(fn () => GoogleMaps::route($loc['lat'].','.$loc['lng'], $target)['minutes'] ?? null, null, false));
        }

        $phone = $live && $driver?->phone ? $driver->phone : ($company['whatsapp'] ?? $company['phone'] ?? null);

        return [
            'reference' => $b->reference,
            'step' => $step,
            'steps' => collect(self::STEPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'pickup' => $b->pickup_location,
            'dropoff' => $b->dropoff_location,
            'pickup_at' => optional($b->start_at)->format('D d M, H:i'),
            'price' => self::price($b, $step),
            'driver' => $driver && ! in_array($step, ['requested', 'price', 'booked', 'cancelled'], true) ? ['name' => strtok((string) $driver->name, ' ')] : null,
            'car' => $car ? ['name' => trim($car->brand.' '.$car->model), 'colour' => $car->colour, 'plate' => $car->registration_number] : ($b->vehicleCategory ? ['name' => $b->vehicleCategory->name] : null),
            'phone' => $phone,
            'phone_is_driver' => (bool) ($live && $driver?->phone),
            'location' => $loc,
            'eta_minutes' => $eta,
            'free_waiting' => self::freeMinutes($b),
            'live' => $live,
        ];
    }
}
