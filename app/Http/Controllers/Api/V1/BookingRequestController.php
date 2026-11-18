<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ServiceType;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\Trip;
use App\Models\TripDate;
use App\Support\Availability;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Website enquiries, booking requests and bookings. A request always becomes a new
 * lead in the CRM (Yacht Trips or Car Rental pipeline). With request_type "booking"
 * (the website's "Book now", 30 Oct, decision H-7) a "Pending payment" booking is made
 * as well (App\Support\Site\WebsiteBooking); it is never confirmed here.
 * Prices and places are worked out here; anything the browser sends about
 * price, availability or publication is ignored.
 */
class BookingRequestController extends Controller
{
    public const TYPES = ['yacht_trip', 'package', 'car', 'yacht', 'transfer'];

    public function store(Request $request): JsonResponse
    {
        // Bots fill hidden fields: answer like a success, store nothing.
        if (filled($request->input('website'))) {
            return response()->json(['data' => ['status' => 'received', 'confirmed' => false]], 202);
        }

        $data = $request->validate([
            'product_type' => ['required', Rule::in(self::TYPES)],
            'product_slug' => ['required', 'string', 'max:255'],
            'request_type' => ['sometimes', Rule::in(['enquiry', 'booking_request', 'booking'])],
            'departure_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
            // Cars and yacht charters (28 Oct): exact times give a server price estimate.
            'start_at' => ['nullable', 'date_format:Y-m-d H:i', 'after_or_equal:today'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'drive_mode' => ['nullable', Rule::in(['self_drive', 'with_driver'])],
            // Limousine & transfers (28 Oct-2): product_slug = vehicle category, service = transfer service.
            'service' => ['nullable', 'string', 'max:255'], // 11 Nov: empty = the distance ride
            'pickup_ll' => ['nullable', 'string', 'max:40', 'regex:/^-?\d{1,2}(\.\d+)?,\s?-?\d{1,3}(\.\d+)?$/'],
            'dropoff_ll' => ['nullable', 'string', 'max:40', 'regex:/^-?\d{1,2}(\.\d+)?,\s?-?\d{1,3}(\.\d+)?$/'],
            'pickup' => ['required_if:product_type,transfer', 'nullable', 'string', 'min:3', 'max:255'],
            'dropoff' => ['nullable', 'string', 'min:3', 'max:255'],
            'stops' => ['sometimes', 'array', 'max:5'],
            'stops.*' => ['string', 'min:3', 'max:255'],
            'return_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'flight_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 \-]{2,20}$/'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'email' => ['nullable', 'required_if:request_type,booking', 'email:rfc', 'max:255'],
            // Website booking (30 Oct): delivery / collection, the address and optional extras from the price list.
            'delivery' => ['sometimes', 'boolean'],
            'collection' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'extras' => ['sometimes', 'array', 'max:20'],
            'extras.*' => ['string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:150'],
            'lead_ref' => ['nullable', 'string', 'max:40'], // 6 Nov (limousine L4): booking link sent from a lead
        ]);

        $key = $data['idempotency_key'] ?? $request->header('Idempotency-Key');
        $externalId = $key ? 'web:'.substr(preg_replace('/[^A-Za-z0-9_\-:.]/', '', (string) $key), 0, 60) : null;
        $type = $data['request_type'] ?? 'enquiry';

        $result = DB::transaction(function () use ($data, $externalId, $type) {
            [$service, $item] = $this->product($data['product_type'], $data['product_slug']);
            if ($item instanceof \App\Models\VehicleCategory) {
                $transferService = TransferQuote::resolve($data['service'] ?? null, $item->slug)[0];
                if (empty($data['start_at'])) {
                    throw ValidationException::withMessages(['start_at' => 'Choose the pickup date and time.']);
                }
            }
            $guests = (int) ($data['guests'] ?? 0) ?: null;
            [$departure, $tripDate, $start, $end] = $this->dates($item, $data, $guests);

            // The same request sent twice (double click, retry) returns the first lead (and its booking).
            if ($externalId && ($existing = Lead::where('external_id', $externalId)->first())) {
                return [$existing, $item, $type, $guests, $departure ?? $tripDate, true, 200, [], $existing->bookings()->latest('id')->first(), false];
            }
            // 6 Nov (limousine L4): from an agent's booking link the booking joins that lead (and its agent).
            if ($linked = \App\Support\Transfers\LimoLink::lead($data['lead_ref'] ?? null)) {
                $estimate = $this->estimate($item, $guests, $departure, $data);
                $booking = $type === 'booking' ? \App\Support\Site\WebsiteBooking::make($linked, $item, $data, $departure, $tripDate, $start, $end, $guests, $estimate) : null;
                rescue(fn () => \App\Models\LeadActivity::create(['lead_id' => $linked->id, 'type' => 'note', 'body' => 'Booked from the link the agent sent'.($booking ? ': '.$booking->reference : '').'.']), report: false);

                return [$linked, $item, $type, $guests, $departure ?? $tripDate, false, 201, $estimate, $booking, (bool) $booking];
            }
            $recent = Phone::key($data['phone']) === null ? null : Lead::query()
                ->where('interested_in_type', $item->getMorphClass())
                ->where('interested_in_id', $item->getKey())
                ->where('created_at', '>=', now()->subMinutes(10))
                ->where(fn ($q) => Phone::whereMatches($q, ['phone'], $data['phone']))
                ->latest()
                ->first();
            if ($recent) {
                return [$recent, $item, $type, $guests, $departure ?? $tripDate, true, 200, [], $type === 'booking' ? $recent->bookings()->latest('id')->first() : null, false];
            }

            $estimate = $this->estimate($item, $guests, $departure, $data);

            $lead = Lead::create([
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => $data['email'] ?? null,
                'lead_source_id' => LeadSource::firstOrCreate(['slug' => 'website'], ['name' => 'Website', 'is_active' => true])->id,
                'channel' => 'website',
                'service_type' => $service,
                'interested_in_type' => $item->getMorphClass(),
                'interested_in_id' => $item->getKey(),
                'package_departure_id' => $departure?->id,
                'trip_date_id' => $tripDate?->id,
                'preferred_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
                'guests' => $guests,
                'budget' => $estimate['amount'] ?? null,
                'message' => $this->message($type, $item, $guests, $departure ?? $tripDate, $estimate, $data['message'] ?? null, $data),
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'external_id' => $externalId,
                'priority' => in_array($type, ['booking_request', 'booking'], true) ? 'hot' : 'warm',
            ]);

            \App\Support\Leads\Assignment::auto($lead); // business settings: manual (default) does nothing

            $booking = $type === 'booking'
                ? \App\Support\Site\WebsiteBooking::make($lead, $item, $data, $departure, $tripDate, $start, $end, $guests, $estimate)
                : null;

            return [$lead, $item, $type, $guests, $departure ?? $tripDate, false, 201, $estimate, $booking, (bool) $booking];
        });

        [$lead, $item, $type, $guests, $date, $duplicate, $status, $estimate, $booking, $isNew] = $result;
        $account = null;
        if ($booking && $isNew) {
            $account = \App\Support\Site\WebsiteBooking::afterCreated($booking, (string) $data['email'], app()->getLocale() === 'ar' || $request->header('Accept-Language') === 'ar' ? 'ar' : 'en')['account'];
        }

        return $this->respond($lead, $item, $type, $guests, $date, $duplicate, $status, $estimate, $booking, $account);
    }

    /** The published product, looked up by slug. @return array{0: ServiceType, 1: \Illuminate\Database\Eloquent\Model} */
    protected function product(string $type, string $slug): array
    {
        $item = match ($type) {
            'yacht_trip' => Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->where('slug', $slug)->first(),
            'package' => Package::query()->publiclyVisible()->active()->where('slug', $slug)->first(),
            'car' => Car::query()->publiclyVisible()->where('slug', $slug)->first(),
            'yacht' => \App\Models\Yacht::query()->publiclyVisible()->where('slug', $slug)->first(),
            'transfer' => \App\Models\VehicleCategory::query()->where('is_active', true)->where('is_published', true)->where('slug', $slug)->first(),
        };

        if (! $item) {
            throw ValidationException::withMessages(['product_slug' => 'This product is not available.']);
        }

        return [match ($type) { 'yacht_trip' => ServiceType::Trip, 'package' => ServiceType::Package, 'yacht' => ServiceType::Yacht, 'transfer' => ServiceType::Transfer, default => ServiceType::Car }, $item];
    }

    /** Departure / date checks, done with the latest data (and rows locked) on the server. */
    protected function dates($item, array $data, ?int $guests): array
    {
        $departure = $tripDate = null;
        $start = ! empty($data['date']) ? Carbon::parse($data['date']) : null;
        $end = ! empty($data['end_date']) ? Carbon::parse($data['end_date']) : null;
        if (! empty($data['start_at'])) {
            $start = Carbon::parse($data['start_at']);
            $end = ! empty($data['end_at']) ? Carbon::parse($data['end_at']) : $end;
        }

        if ($item instanceof Package) {
            if ($item->min_guests && $guests && $guests < $item->min_guests) {
                throw ValidationException::withMessages(['guests' => "This package needs at least {$item->min_guests} guests."]);
            }
            if ($item->max_guests && $guests && $guests > $item->max_guests) {
                throw ValidationException::withMessages(['guests' => "This package takes up to {$item->max_guests} guests per booking."]);
            }
            if ($item->usesDepartures()) {
                if (empty($data['departure_id'])) {
                    throw ValidationException::withMessages(['departure_id' => 'Please choose a departure date.']);
                }
                $departure = PackageDeparture::with('package')->where('package_id', $item->id)->find($data['departure_id']);
                if (! $departure || ! $departure->isBookable()) {
                    throw ValidationException::withMessages(['departure_id' => 'This departure is not available.']);
                }
                $left = Availability::packageSeatsLeft($departure);
                if ($left !== null && ($guests ?? 1) > $left) {
                    throw ValidationException::withMessages(['guests' => $left ? "Only {$left} place(s) left on this departure." : 'This departure is full.']);
                }
                [$start, $end] = [$departure->startsAt(), $departure->endsAt()];
            } elseif (! $start) {
                throw ValidationException::withMessages(['date' => 'Please choose a preferred date.']);
            } elseif ($item->booking_cutoff_days && today()->addDays($item->booking_cutoff_days)->greaterThan($start)) {
                throw ValidationException::withMessages(['date' => "Please book at least {$item->booking_cutoff_days} days ahead."]);
            } elseif ($item->duration_days > 1 && ! $end) {
                $end = $start->copy()->addDays($item->duration_days - 1);
            }
        }

        if ($item instanceof Trip) {
            if (! empty($data['departure_id'])) {
                $tripDate = TripDate::with('trip')->where('trip_id', $item->id)->find($data['departure_id']);
                if (! $tripDate || ! $tripDate->is_available || $tripDate->date->isBefore(today())) {
                    throw ValidationException::withMessages(['departure_id' => 'This trip date is not available.']);
                }
                $left = Availability::seatsLeft($tripDate);
                if ($left !== null && ($guests ?? 1) > $left) {
                    throw ValidationException::withMessages(['guests' => $left ? "Only {$left} seat(s) left on this date." : 'This date is full.']);
                }
                $start = $tripDate->date->copy();
            } elseif (! $start) {
                throw ValidationException::withMessages(['departure_id' => 'Please choose a trip date.']);
            }
            if ($item->guest_capacity && $guests && $guests > $item->guest_capacity) {
                throw ValidationException::withMessages(['guests' => "This trip takes up to {$item->guest_capacity} guests."]);
            }
        }

        return [$departure, $tripDate, $start, $end];
    }

    /** Server-side price estimate (never confirmed here). */
    protected function estimate($item, ?int $guests, ?PackageDeparture $departure, array $data = []): array
    {
        // Limousine / transfer: the same quote as POST /price-estimate (route from the CRM, never the browser).
        if ($item instanceof \App\Models\VehicleCategory) {
            $q = rescue(fn () => TransferQuote::quote(request()), null, false);
            $total = $q['price']['total'] ?? null;

            return $total ? ['amount' => (float) $total, 'currency' => 'AED', 'unit_amount' => (float) $total, 'unit' => 'per_transfer'] : [];
        }
        // Car or charter with exact times: the CRM's own price engine.
        if (($item instanceof Car || $item instanceof \App\Models\Yacht) && ! empty($data['start_at']) && ! empty($data['end_at'])) {
            $q = \App\Support\Pricing\PriceEngine::calculate([
                'service_type' => $item instanceof Car ? ServiceType::Car->value : ServiceType::Yacht->value, 'item' => $item,
                'start_at' => $data['start_at'], 'end_at' => $data['end_at'], 'guests' => $guests,
                'drive_mode' => $item instanceof Car ? ($data['drive_mode'] ?? ($item->self_drive ? 'self_drive' : 'with_driver')) : null,
            ]);
            if ($q) {
                $t = \App\Support\Pricing\PriceEngine::totals((float) $q['base_amount'], $q['lines'], 0, \App\Support\Tax::defaultRate(), \App\Support\Tax::defaultInclusive());

                return ['amount' => $t['total'], 'currency' => 'AED', 'unit_amount' => (float) $q['rate'], 'unit' => $q['rate_type']];
            }

            return [];
        }
        if ($item instanceof Package && ($q = $item->priceFor($guests, $departure))) {
            return ['amount' => $q[3], 'currency' => $item->currency ?: 'AED', 'unit_amount' => $q[2], 'unit' => $item->isPerPerson() ? 'per_person' : 'per_package'];
        }
        if ($item instanceof Trip && $item->price !== null) {
            $perTrip = $item->price_unit === 'per_trip';
            $amount = $perTrip ? (float) $item->price : max(1, (int) $guests) * (float) $item->price;

            return ['amount' => round($amount, 2), 'currency' => 'AED', 'unit_amount' => (float) $item->price, 'unit' => $perTrip ? 'per_trip' : 'per_person'];
        }

        return [];
    }

    protected function message(string $type, $item, ?int $guests, $date, array $estimate, ?string $text, array $data = []): string
    {
        $lines = [
            match ($type) { 'booking' => '[Website booking]', 'booking_request' => '[Website booking request]', default => '[Website enquiry]' },
            $item->name.($date instanceof PackageDeparture ? ' · departure '.$date->start_date->format('d M Y') : '').($date instanceof TripDate ? ' · '.$date->date->format('d M Y') : ''),
            ! empty($data['start_at']) ? 'From '.$data['start_at'].(! empty($data['end_at']) ? ' to '.$data['end_at'] : '').(! empty($data['drive_mode']) ? ' · '.str_replace('_', '-', $data['drive_mode']) : '') : null,
            ! empty($data['service']) ? 'Service: '.$data['service'] : null,
            ! empty($data['pickup']) ? 'Pickup: '.$data['pickup'] : null,
            ! empty($data['stops']) ? 'Stops: '.implode(' · ', (array) $data['stops']) : null,
            ! empty($data['dropoff']) ? 'Drop-off: '.$data['dropoff'] : null,
            ! empty($data['return_at']) ? 'Return pickup: '.$data['return_at'] : null,
            ! empty($data['flight_number']) ? 'Flight: '.$data['flight_number'] : null,
            ! empty($data['luggage']) ? $data['luggage'].' bag(s)' : null,
            $guests ? $guests.' guest(s)' : null,
            ! empty($estimate['amount']) ? 'Website price estimate: '.$estimate['currency'].' '.number_format($estimate['amount'], 2).' (to be confirmed)' : null,
            filled($text) ? "\n".trim($text) : null,
        ];

        return trim(implode("\n", array_filter($lines)));
    }

    protected function respond(Lead $lead, $item, string $type, ?int $guests, $date, bool $duplicate, int $status, array $estimate = [], ?\App\Models\Booking $booking = null, ?string $account = null): JsonResponse
    {
        if ($booking) {
            return response()->json([
                'data' => [
                    'reference' => $booking->reference,
                    'lead_reference' => sprintf('LD-%05d', $lead->id),
                    'status' => $booking->status->value,
                    'request_type' => $type,
                    'booked' => true,
                    'confirmed' => false,
                    'duplicate' => $duplicate,
                    'hold_until' => $booking->hold_until?->toIso8601String(),
                    'hold_hours' => \App\Support\Site\WebsiteBooking::holdHours(),
                    'total' => (float) $booking->total,
                    'currency' => 'AED',
                    'account' => $account,
                    // 11 Nov (limousine): a ride request waits for the agent's price; the customer follows it on one page.
                    'awaiting_price' => $ride = \App\Support\Transfers\RideFlow::isRequest($booking),
                    'ride_url' => $booking->service_type === ServiceType::Transfer ? \App\Support\Transfers\Tracking::link($booking) : null,
                    'message' => $ride
                        ? 'Thank you. We have your ride request. Our team confirms the final price shortly; you then accept and pay on your ride page.'
                        : 'Thank you. Your booking is saved and waiting for payment. Our team will send you the payment link.',
                    'product' => ['type' => $item->getMorphClass() === 'trip' ? 'yacht_trip' : $item->getMorphClass(), 'slug' => $item->slug, 'name' => $item->name],
                ],
            ], $status);
        }

        return response()->json([
            'data' => [
                'reference' => sprintf('LD-%05d', $lead->id),
                'status' => 'received',
                'request_type' => $type,
                'booked' => false,
                'confirmed' => false,
                'duplicate' => $duplicate,
                'message' => in_array($type, ['booking_request', 'booking'], true)
                    ? 'Thank you. Your request is with our team. We will confirm availability and the final price shortly. Nothing is booked or charged yet.'
                    : 'Thank you. Our team will contact you shortly.',
                'product' => ['type' => $item->getMorphClass() === 'trip' ? 'yacht_trip' : $item->getMorphClass(), 'slug' => $item->slug, 'name' => $item->name],
                'departure_id' => $date?->id,
                'guests' => $guests,
                'price_estimate' => $estimate ? $estimate + ['note' => 'Estimate only. The final price is confirmed by our team.'] : null,
            ],
        ], $status);
    }
}
