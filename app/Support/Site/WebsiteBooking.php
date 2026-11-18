<?php

namespace App\Support\Site;

use App\Enums\BookingStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripDate;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\Yacht;
use App\Notifications\WebsiteBookingReceived;
use App\Support\Accounts;
use App\Support\Availability;
use App\Support\Maps\GoogleMaps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Bookings made on the website (M3, 30 Oct, decision H-7 by Bilal):
 *  - the booking is saved as "Pending payment" and holds the car / yacht / seats;
 *  - the price is the CRM price engine's (the booking's own saving hook prices it again);
 *  - if nothing is paid within the hours set in Business settings (24 by default) the
 *    hold ends and crm:release-website-holds cancels the booking;
 *  - no payment link is sent automatically: staff choose the amount and send the link;
 *  - the customer gets an email with the booking and a "choose your password" link for
 *    their account (nobody else ever sees or sets the password).
 * When a booking can't be made automatically (missing details, no price) the request
 * stays a lead, as before. A clash (taken meanwhile) is reported to the visitor.
 */
class WebsiteBooking
{
    /** Validation keys that mean "not free any more". */
    protected const CLASH_KEYS = ['data.end_at', 'data.guests', 'data.start_at'];

    public static function holdHours(): int
    {
        return max(0, (int) Setting::read('website_booking_hold_hours', 24));
    }

    /**
     * Make the booking for a fresh website lead. Returns null when it has to stay a lead.
     *
     * @throws ValidationException when the dates / seats were taken meanwhile
     */
    public static function make(Lead $lead, $item, array $data, ?PackageDeparture $departure, ?TripDate $tripDate, ?Carbon $start, ?Carbon $end, ?int $guests, array $estimate): ?Booking
    {
        if (empty($estimate['amount'])) {
            self::note($lead, 'No price could be worked out, so the request was kept as a lead.');

            return null;
        }

        $customer = $lead->convertToCustomer();
        if (blank($customer->email) && filled($data['email'] ?? null)) {
            $customer->forceFill(['email' => $data['email']])->saveQuietly();
        }

        $hours = self::holdHours();
        $attrs = [
            'status' => BookingStatus::PendingPayment,
            'source' => 'website',
            'hold_until' => $hours > 0 ? now()->addHours($hours) : null,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'guests' => $guests,
            'notes' => trim("[Website booking]\n".$lead->message),
        ];

        $attrs += match (true) {
            $item instanceof Car => self::car($item, $data, $start, $end),
            $item instanceof Yacht => ['service_type' => ServiceType::Yacht, 'bookable_type' => 'yacht', 'bookable_id' => $item->id, 'start_at' => $start, 'end_at' => $end],
            $item instanceof Trip => self::trip($item, $tripDate),
            $item instanceof Package => self::package($item, $departure, $start, $end),
            $item instanceof VehicleCategory => self::transfer($item, $data),
            default => [],
        };
        // 11 Nov (limousine): the website price is an estimate. The ride waits for an agent to confirm
        // the price (RideFlow::confirm) before it waits for payment; nothing is held until then.
        if ($item instanceof VehicleCategory && \App\Support\Transfers\RideFlow::agentConfirms()) {
            $attrs['status'] = BookingStatus::Inquiry;
            $attrs['hold_until'] = null;
            $pins = array_filter(['Pickup pin' => $data['pickup_ll'] ?? null, 'Drop-off pin' => $data['dropoff_ll'] ?? null]);
            $attrs['notes'] = trim("[Website ride request]\n".$lead->message.collect($pins)->map(fn ($ll, $k) => "\n{$k}: https://www.google.com/maps?q=".str_replace(' ', '', $ll))->implode(''));
        }
        if (empty($attrs['start_at']) || empty($attrs['end_at'])) {
            self::note($lead, 'The dates were not complete, so the request was kept as a lead.');

            return null;
        }

        try {
            // A savepoint: if the booking can't be saved, the lead stays as it is.
            return DB::transaction(fn () => tap(new Booking($attrs))->save());
        } catch (ValidationException $e) {
            $keys = array_keys($e->errors());
            $message = collect($e->errors())->flatten()->first();
            if (array_intersect($keys, self::CLASH_KEYS) && self::isClash((string) $message)) {
                throw ValidationException::withMessages(['start_at' => self::clashMessage($item)]);
            }
            self::note($lead, 'Could not be booked automatically: '.$message);

            return null;
        }
    }

    protected static function car(Car $car, array $data, ?Carbon $start, ?Carbon $end): array
    {
        $delivery = ! empty($data['delivery']) && $car->delivery_available;

        return [
            'service_type' => ServiceType::Car, 'bookable_type' => 'car', 'bookable_id' => $car->id,
            'start_at' => $start, 'end_at' => $end,
            'drive_mode' => $data['drive_mode'] ?? ($car->self_drive ? 'self_drive' : 'with_driver'),
            'delivery_method' => $delivery ? 'delivery' : 'office',
            'return_method' => ! empty($data['collection']) && $car->delivery_available ? 'collection' : 'office',
            'pickup_location' => $delivery ? (trim((string) ($data['address'] ?? '')) ?: null) : null,
            'extras' => collect((array) ($data['extras'] ?? []))->filter()->map(fn ($k) => ['key' => (string) $k, 'kind' => 'extra'])->values()->all() ?: null,
        ];
    }

    protected static function trip(Trip $trip, ?TripDate $date): array
    {
        if (! $date) {
            return [];
        }
        [$from, $to] = Availability::departureWindow($date);

        return ['service_type' => ServiceType::Trip, 'bookable_type' => 'trip', 'bookable_id' => $trip->id, 'trip_date_id' => $date->id, 'start_at' => $from, 'end_at' => $to];
    }

    protected static function package(Package $package, ?PackageDeparture $departure, ?Carbon $start, ?Carbon $end): array
    {
        $base = ['service_type' => ServiceType::Package, 'bookable_type' => 'package', 'bookable_id' => $package->id];
        if ($departure) {
            return $base + ['package_departure_id' => $departure->id, 'start_at' => $departure->startsAt(), 'end_at' => $departure->endsAt()];
        }
        if (! $start) {
            return [];
        }
        $from = $start->copy()->setTime(9, 0);
        $days = max(1, (int) ($package->duration_days ?: 1));
        $to = $end ? $end->copy()->setTime(18, 0) : ($days > 1 ? $from->copy()->addDays($days - 1)->setTime(18, 0) : $from->copy()->addHours(8));

        return $base + ['start_at' => $from, 'end_at' => $to];
    }

    /** Limousine: the same route and price as the estimate (route from Google, signed by the server). */
    protected static function transfer(VehicleCategory $category, array $data): array
    {
        $service = \App\Http\Controllers\Api\V1\TransferQuote::resolve($data['service'] ?? null, $category->slug)[0];
        [$from, $to] = \App\Http\Controllers\Api\V1\TransferQuote::points($data);
        $start = Carbon::parse($data['start_at']);
        $hourly = $service->isHourly();
        $returnAt = filled($data['return_at'] ?? null) ? Carbon::parse($data['return_at']) : null;
        $stops = array_values(array_filter((array) ($data['stops'] ?? [])));
        $attrs = [
            'service_type' => ServiceType::Transfer, 'transfer_service_id' => $service->id, 'vehicle_category_id' => $category->id,
            'journey_type' => $hourly ? 'hourly' : ($returnAt ? 'return' : $service->journey_type),
            'pickup_location' => $data['pickup'], 'dropoff_location' => $data['dropoff'] ?? null,
            'stops' => $stops ? array_map(fn ($s) => ['address' => $s], $stops) : null,
            'return_at' => $returnAt, 'flight_number' => $data['flight_number'] ?? null, 'luggage' => $data['luggage'] ?? null,
            'start_at' => $start,
        ];
        if ($hourly) {
            return $attrs + ['end_at' => filled($data['end_at'] ?? null) ? Carbon::parse($data['end_at']) : null];
        }
        if (! GoogleMaps::ready()) {
            return [];
        }
        $route = GoogleMaps::route($from, $to, $stops, $start);
        $back = $returnAt ? rescue(fn () => GoogleMaps::route($to, $from, array_reverse($stops), $returnAt)['km'], $route['km'], false) : null;

        return $attrs + [
            'route_km' => $route['km'], 'return_route_km' => $back, 'route_minutes' => $route['minutes'], 'route_source' => 'google',
            'route' => GoogleMaps::stamp($data['pickup'], (string) ($data['dropoff'] ?? ''), $stops, (float) $route['km'], $back !== null ? (float) $back : null,
                array_filter(['pickup_ll' => GoogleMaps::ll($data['pickup_ll'] ?? null) ? $data['pickup_ll'] : null, 'dropoff_ll' => GoogleMaps::ll($data['dropoff_ll'] ?? null) ? $data['dropoff_ll'] : null])),
            'end_at' => ($returnAt ?? $start)->copy()->addMinutes(max(30, (int) ($route['minutes'] ?: 60))),
        ];
    }

    protected static function isClash(string $message): bool
    {
        return (bool) preg_match('/(booked|taken|left|full|busy|already|not available|overlap|clash)/i', $message);
    }

    protected static function clashMessage($item): string
    {
        return $item instanceof Trip || $item instanceof Package
            ? 'These places were just taken. Please choose another date or fewer guests.'
            : 'Sorry, this was just booked for these dates. Please choose other dates or message us on WhatsApp.';
    }

    protected static function note(Lead $lead, string $why): void
    {
        $lead->forceFill(['message' => trim($lead->message."\n\n[Website] ".$why)])->saveQuietly();
    }

    /**
     * After the booking is saved: the customer's login, the email and the staff alert.
     * Runs after the commit; a mail problem never undoes the booking.
     *
     * @return array{account: string} new / existing / none
     */
    public static function afterCreated(Booking $booking, string $email, string $locale): array
    {
        $booking->loadMissing('customer', 'bookable');
        $account = 'none';
        $loginUrl = null;
        $user = null;

        try {
            $user = User::where('customer_id', $booking->customer_id)->first();
            if (! $user && ! User::where('email', $email)->exists()) {
                $user = Accounts::customerAccount($booking->customer, $email);
            }
            if ($user) {
                // Never logged in: a fresh "choose your password" link. Otherwise: the login page.
                $account = $user->last_login_at ? 'existing' : 'new';
                $loginUrl = $user->last_login_at ? url('/account') : Accounts::inviteUrl($user);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        rescue(function () use ($booking, $user, $email, $loginUrl, $account, $locale) {
            $mail = \App\Support\Transfers\RideFlow::isRequest($booking)
                ? new \App\Notifications\RideRequestReceived($booking, \App\Support\Transfers\Tracking::link($booking), $loginUrl, $account)
                : new WebsiteBookingReceived($booking, $loginUrl, $account, $locale, $user?->email ?? $email);
            // 4 Nov: through the routed mailbox, the email log and (when running) the queue.
            $user && $user->email === $email
                ? \App\Support\Notifications\Notifier::send($user, $mail, $booking)
                : \App\Support\Notifications\Notifier::mail($email, $mail, $booking);
        });

        rescue(fn () => \App\Support\Drivers\Notify::staff('manage-bookings', 'New website booking '.$booking->reference,
            $booking->customer?->name.' · '.$booking->title().' · AED '.number_format((float) $booking->total, 2).' · '.(\App\Support\Transfers\RideFlow::isRequest($booking) ? 'confirm the price for the customer' : 'waiting for payment'),
            \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $booking]), $booking->lead?->assignee, 'success', event: 'website_booking'), report: false);

        return ['account' => $account];
    }
}
