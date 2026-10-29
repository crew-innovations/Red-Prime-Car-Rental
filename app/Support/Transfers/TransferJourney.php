<?php

namespace App\Support\Transfers;

use App\Enums\BookingStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use App\Support\Availability;
use App\Support\Maps\GoogleMaps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Server-side rules for limousine & transfer quotations and bookings (brief
 * 28 Oct-2, 4.2), run on every save whatever the form sent:
 *  - only transfers keep journey fields;
 *  - the service decides the journey type, whether a destination and a flight are needed;
 *  - passengers can't exceed the vehicle category;
 *  - the end time follows the journey (return time, travel time, waiting) unless hired by the hour;
 *  - a typed distance is marked "manual" so nobody mistakes it for a Google route.
 */
class TransferJourney
{
    public const FIELDS = ['transfer_service_id', 'vehicle_category_id', 'journey_type', 'dropoff_location', 'stops', 'return_at', 'flight_number', 'airline',
        'terminal', 'luggage', 'child_seats', 'waiting_minutes', 'route_km', 'route_minutes', 'return_route_km', 'route_source', 'route', 'special_requests',
        'route_override_reason', 'route_override_by'];

    public static function prepare(Model $record): void
    {
        /** @var Booking|Quotation $record */
        if ($record->service_type !== ServiceType::Transfer) {
            foreach (self::FIELDS as $f) {
                if ($record->getAttribute($f) !== null) {
                    $record->setAttribute($f, null);
                }
            }

            return;
        }

        $service = $record->transfer_service_id ? TransferService::withTrashed()->find($record->transfer_service_id) : null;
        $category = $record->vehicle_category_id ? VehicleCategory::withTrashed()->find($record->vehicle_category_id) : null;
        $holding = $record instanceof Booking ? Availability::holds($record->status) : true;

        if (! $service) {
            throw ValidationException::withMessages(['data.transfer_service_id' => 'Pick the transfer service (airport pickup, hourly hire…).']);
        }
        if (! $category) {
            throw ValidationException::withMessages(['data.vehicle_category_id' => 'Pick the vehicle category.']);
        }
        $record->journey_type = $service->isHourly() ? 'hourly' : (in_array($record->journey_type, ['one_way', 'return', 'custom'], true) ? $record->journey_type : $service->journey_type);
        if ($record->journey_type !== 'return') {
            $record->return_at = $record->journey_type === 'hourly' ? null : $record->return_at;
        }
        if ($record->journey_type === 'return' && ! $record->return_at && $holding) {
            throw ValidationException::withMessages(['data.return_at' => 'Set the return pickup time.']);
        }
        if ($record->return_at && $record->start_at && $record->return_at->lte($record->start_at)) {
            throw ValidationException::withMessages(['data.return_at' => 'The return must be after the first pickup.']);
        }

        if ($service->needs_destination && $record->journey_type !== 'hourly' && blank($record->dropoff_location)) {
            throw ValidationException::withMessages(['data.dropoff_location' => 'Enter the destination.']);
        }
        if (blank($record->pickup_location)) {
            throw ValidationException::withMessages(['data.pickup_location' => 'Enter the pickup address.']);
        }
        if ($service->is_airport && $holding && $record instanceof Booking && in_array($record->status, BookingStatus::billable(), true) && blank($record->flight_number)) {
            throw ValidationException::withMessages(['data.flight_number' => 'Airport service: enter the flight number before confirming.']);
        }
        if ($category->passengers && (int) $record->guests > $category->passengers) {
            throw ValidationException::withMessages(['data.guests' => "{$category->name} takes up to {$category->passengers} passengers."]);
        }
        if ($category->luggage && (int) $record->luggage > $category->luggage) {
            throw ValidationException::withMessages(['data.luggage' => "{$category->name} takes up to {$category->luggage} bags."]);
        }
        if ($record->bookable_id && ($car = \App\Models\Car::withTrashed()->find($record->bookable_id)) && $car->vehicle_category_id && (int) $car->vehicle_category_id !== (int) $category->id) {
            throw ValidationException::withMessages(['data.bookable_id' => $car->name.' is not in the '.$category->name.' category.']);
        }

        // Stops: keep only filled addresses.
        $record->stops = collect((array) $record->stops)->map(fn ($s) => is_array($s) ? ['address' => trim((string) ($s['address'] ?? ''))] : ['address' => trim((string) $s)])
            ->filter(fn ($s) => $s['address'] !== '')->values()->all() ?: null;

        self::checkRoute($record, $service, $category, $holding);

        // End time: hourly hire is set by staff; otherwise it follows the journey.
        if ($record->journey_type !== 'hourly' && $record->start_at) {
            $minutes = max(30, (int) ($record->route_minutes ?: 60)) + (int) $record->waiting_minutes;
            $from = $record->return_at ?? $record->start_at;
            $record->end_at = $from->copy()->addMinutes($minutes);
        }
        if ($record->journey_type === 'hourly' && $record->start_at && (! $record->end_at || $record->end_at->lte($record->start_at))) {
            throw ValidationException::withMessages(['data.end_at' => 'Chauffeur hire by the hour: set when it ends.']);
        }
    }

    /**
     * Route distance rules (brief 29 Oct, 4–7):
     *  - a "Google" distance is trusted only with the server's signature for these exact
     *    addresses and km; anything else is "typed by hand" (the Google figure is kept);
     *  - typing a distance needs the "Change prices" permission and, before confirming or
     *    sending, a reason (who and when are recorded);
     *  - no rate for the category: nothing else is used; a price typed by hand needs a reason.
     */
    protected static function checkRoute(Model $record, TransferService $service, VehicleCategory $category, bool $holding): void
    {
        $user = auth()->user();
        $hourly = $record->journey_type === 'hourly';
        $stops = collect((array) $record->stops)->pluck('address')->filter()->values()->all();

        if ($hourly) {
            $record->route_override_reason = null;
        } else {
            $km = $record->route_km !== null ? (float) $record->route_km : null;
            $back = $record->return_route_km !== null ? (float) $record->return_route_km : null;
            $googleOk = $record->route_source === 'google' && GoogleMaps::stampValid((array) $record->route, $record->pickup_location, $record->dropoff_location, $stops, $km, $back);
            if (! $googleOk && $km !== null) {
                $prev = (array) ($record->getOriginal('route') ?? []) + (array) ($record->route ?? []);
                if (($record->route_source === 'google' || $record->getOriginal('route_source') === 'google') && ($prev['km'] ?? null)) {
                    $record->route = ['google_km' => $prev['km'], 'google_return_km' => $prev['return_km'] ?? null, 'google_at' => $prev['at'] ?? null];
                } elseif (! isset($prev['google_km'])) {
                    $record->route = null;
                }
                $record->route_source = 'manual';
            }
            if ($km === null && $record->route_source !== 'google') {
                $record->route_source = null;
            }
            if ($googleOk) {
                $record->route_override_reason = null;
                $record->route_override_by = null;
            }

            $manualChanged = $record->route_source === 'manual' && (! $record->exists || $record->isDirty(['route_km', 'return_route_km', 'route_source']));
            if ($manualChanged && $user && ! \App\Support\Access::allows('adjust-prices')) {
                throw ValidationException::withMessages(['data.route_km' => 'Only people allowed to change prices can type a distance. Calculate the route with Google Maps instead.']);
            }
            if ($record->route_source === 'manual' && $record->isDirty('route_override_reason') && filled($record->route_override_reason)) {
                $record->route_override_by = $user?->id;
            }
        }

        $confirming = $record instanceof Booking
            ? in_array($record->status, BookingStatus::billable(), true)
            : in_array($record->status, [\App\Enums\QuotationStatus::Sent, \App\Enums\QuotationStatus::Negotiation, \App\Enums\QuotationStatus::Accepted], true);
        $relevant = ! $record->exists || $record->isDirty(['status', 'route_km', 'return_route_km', 'route_source', 'route_override_reason', 'pickup_location', 'dropoff_location', 'stops', 'vehicle_category_id', 'transfer_service_id', 'start_at']);
        if (! $confirming || ! $relevant) {
            return;
        }
        $fixed = ! $hourly && \App\Models\TransferFixedRoute::forRoute($category->id, $record->pickup_location, $record->dropoff_location);
        if (! $hourly && ! $fixed && $record->route_source !== 'google' && blank($record->route_override_reason)) {
            throw ValidationException::withMessages(['data.route_km' => (float) $record->route_km > 0
                ? 'The distance was typed by hand: say why before confirming (or calculate the route with Google Maps).'
                : 'Calculate the route with Google Maps (or type the distance and say why) before confirming.']);
        }
        $rate = \App\Models\TransferRate::for($category->id, $service->id, $record->start_at ?? now());
        if (! $rate && blank($record->adjustment_reason)) {
            throw ValidationException::withMessages(['data.base_amount' => 'No transfer rate for '.$category->name.' (Pricing › Transfer rates). No other rate is used: add one, or type the price and give the reason.']);
        }
        if (! $rate && $user && ! \App\Support\Access::allows('adjust-prices')) {
            throw ValidationException::withMessages(['data.base_amount' => 'No transfer rate for '.$category->name.'. Only people allowed to change prices can price it by hand.']);
        }
    }

    /** Price engine input for a saved transfer. */
    public static function priceInput(Model $record): array
    {
        return [
            'transfer_service_id' => $record->transfer_service_id, 'vehicle_category_id' => $record->vehicle_category_id,
            'journey_type' => $record->journey_type, 'route_km' => $record->route_km, 'return_km' => $record->return_route_km,
            'route_minutes' => $record->route_minutes, 'route_source' => $record->route_source, 'stops' => $record->stops,
            'waiting_minutes' => $record->waiting_minutes, 'return_at' => $record->return_at,
            'pickup_location' => $record->pickup_location, 'dropoff_location' => $record->dropoff_location,
            'rate_id' => self::pinnedRate($record),
        ];
    }

    /** The rate a confirmed booking / sent quotation was priced with, unless staff chose "Use the current rate". */
    public static function pinnedRate(Model $record): ?int
    {
        if (! $record->exists || ! empty($record->repriceWithCurrentRate)) {
            return null;
        }
        $agreed = $record instanceof Booking
            ? in_array(BookingStatus::tryFrom((string) ($record->getRawOriginal('status'))), BookingStatus::billable(), true)
            : in_array((string) $record->getRawOriginal('status'), ['sent', 'negotiation', 'accepted'], true);

        return $agreed ? (data_get($record->getOriginal('price_breakdown'), 'transfer.rate_id') ?: null) : null;
    }
}
