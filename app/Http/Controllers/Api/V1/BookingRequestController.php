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
 * Website enquiries and booking requests. A request always becomes a new
 * lead in the CRM (Yacht Trips or Car Rental pipeline). It never creates or
 * confirms a booking: staff follow the normal quotation / booking process.
 * Prices and places are worked out here; anything the browser sends about
 * price, availability or publication is ignored.
 */
class BookingRequestController extends Controller
{
    public const TYPES = ['yacht_trip', 'package', 'car', 'yacht'];

    public function store(Request $request): JsonResponse
    {
        // Bots fill hidden fields: answer like a success, store nothing.
        if (filled($request->input('website'))) {
            return response()->json(['data' => ['status' => 'received', 'confirmed' => false]], 202);
        }

        $data = $request->validate([
            'product_type' => ['required', Rule::in(self::TYPES)],
            'product_slug' => ['required', 'string', 'max:255'],
            'request_type' => ['sometimes', Rule::in(['enquiry', 'booking_request'])],
            'departure_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
            // Cars and yacht charters (28 Oct): exact times give a server price estimate.
            'start_at' => ['nullable', 'date_format:Y-m-d H:i', 'after_or_equal:today'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'drive_mode' => ['nullable', Rule::in(['self_drive', 'with_driver'])],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:150'],
        ]);

        $key = $data['idempotency_key'] ?? $request->header('Idempotency-Key');
        $externalId = $key ? 'web:'.substr(preg_replace('/[^A-Za-z0-9_\-:.]/', '', (string) $key), 0, 60) : null;
        $type = $data['request_type'] ?? 'enquiry';

        return DB::transaction(function () use ($data, $externalId, $type) {
            [$service, $item] = $this->product($data['product_type'], $data['product_slug']);
            $guests = (int) ($data['guests'] ?? 0) ?: null;
            [$departure, $tripDate, $start, $end] = $this->dates($item, $data, $guests);

            // The same request sent twice (double click, retry) returns the first lead.
            if ($externalId && ($existing = Lead::where('external_id', $externalId)->first())) {
                return $this->respond($existing, $item, $type, $guests, $departure ?? $tripDate, true, 200);
            }
            $recent = Phone::key($data['phone']) === null ? null : Lead::query()
                ->where('interested_in_type', $item->getMorphClass())
                ->where('interested_in_id', $item->getKey())
                ->where('created_at', '>=', now()->subMinutes(10))
                ->where(fn ($q) => Phone::whereMatches($q, ['phone'], $data['phone']))
                ->latest()
                ->first();
            if ($recent) {
                return $this->respond($recent, $item, $type, $guests, $departure ?? $tripDate, true, 200);
            }

            $estimate = $this->estimate($item, $guests, $departure, $data);

            $lead = Lead::create([
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'email' => $data['email'] ?? null,
                'lead_source_id' => LeadSource::firstOrCreate(['slug' => 'website'], ['name' => 'Website', 'is_active' => true])->id,
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
                'priority' => $type === 'booking_request' ? 'hot' : 'warm',
            ]);

            return $this->respond($lead, $item, $type, $guests, $departure ?? $tripDate, false, 201, $estimate);
        });
    }

    /** The published product, looked up by slug. @return array{0: ServiceType, 1: \Illuminate\Database\Eloquent\Model} */
    protected function product(string $type, string $slug): array
    {
        $item = match ($type) {
            'yacht_trip' => Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->where('slug', $slug)->first(),
            'package' => Package::query()->publiclyVisible()->active()->where('slug', $slug)->first(),
            'car' => Car::query()->publiclyVisible()->where('slug', $slug)->first(),
            'yacht' => \App\Models\Yacht::query()->publiclyVisible()->where('slug', $slug)->first(),
        };

        if (! $item) {
            throw ValidationException::withMessages(['product_slug' => 'This product is not available.']);
        }

        return [match ($type) { 'yacht_trip' => ServiceType::Trip, 'package' => ServiceType::Package, 'yacht' => ServiceType::Yacht, default => ServiceType::Car }, $item];
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
            $type === 'booking_request' ? '[Website booking request]' : '[Website enquiry]',
            $item->name.($date instanceof PackageDeparture ? ' · departure '.$date->start_date->format('d M Y') : '').($date instanceof TripDate ? ' · '.$date->date->format('d M Y') : ''),
            ! empty($data['start_at']) ? 'From '.$data['start_at'].(! empty($data['end_at']) ? ' to '.$data['end_at'] : '').(! empty($data['drive_mode']) ? ' · '.str_replace('_', '-', $data['drive_mode']) : '') : null,
            $guests ? $guests.' guest(s)' : null,
            ! empty($estimate['amount']) ? 'Website price estimate: '.$estimate['currency'].' '.number_format($estimate['amount'], 2).' (to be confirmed)' : null,
            filled($text) ? "\n".trim($text) : null,
        ];

        return trim(implode("\n", array_filter($lines)));
    }

    protected function respond(Lead $lead, $item, string $type, ?int $guests, $date, bool $duplicate, int $status, array $estimate = []): JsonResponse
    {
        return response()->json([
            'data' => [
                'reference' => sprintf('LD-%05d', $lead->id),
                'status' => 'received',
                'request_type' => $type,
                'confirmed' => false,
                'duplicate' => $duplicate,
                'message' => $type === 'booking_request'
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
