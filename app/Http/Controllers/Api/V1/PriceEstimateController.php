<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\Trip;
use App\Models\TripDate;
use App\Models\Yacht;
use App\Support\Availability;
use App\Support\Pricing\PriceEngine;
use App\Support\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Website price estimate and availability (28 Oct brief, Priority 6.2). The same
 * PriceEngine the CRM uses works out the price on the server; nothing here books,
 * holds or confirms anything, and no internal booking details are returned.
 */
class PriceEstimateController extends Controller
{
    public const TYPES = ['car', 'yacht', 'yacht_trip', 'package'];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_type' => ['required', Rule::in(self::TYPES)],
            'product_slug' => ['required', 'string', 'max:255'],
            'start_at' => ['nullable', 'date_format:Y-m-d H:i', 'after_or_equal:today'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'drive_mode' => ['nullable', Rule::in(['self_drive', 'with_driver'])],
            'guests' => ['nullable', 'integer', 'min:1', 'max:500'],
            'departure_id' => ['nullable', 'integer', 'min:1'],
            'delivery' => ['sometimes', 'boolean'],
            'collection' => ['sometimes', 'boolean'],
            'extras' => ['sometimes', 'array', 'max:20'],
            'extras.*' => ['string', 'max:80'],
        ]);

        $item = self::product($data['product_type'], $data['product_slug']);
        $service = match (true) {
            $item instanceof Car => ServiceType::Car,
            $item instanceof Yacht => ServiceType::Yacht,
            $item instanceof Trip => ServiceType::Trip,
            default => ServiceType::Package,
        };
        $start = filled($data['start_at'] ?? null) ? Carbon::parse($data['start_at']) : null;
        $end = filled($data['end_at'] ?? null) ? Carbon::parse($data['end_at']) : null;
        $departure = $tripDate = null;

        if ($item instanceof Car || $item instanceof Yacht) {
            if (! $start || ! $end) {
                throw ValidationException::withMessages(['start_at' => 'Please choose the start and end date and time.']);
            }
        }
        if ($item instanceof Car) {
            $mode = $data['drive_mode'] ?? ($item->self_drive ? 'self_drive' : 'with_driver');
            if (($mode === 'self_drive' && ! $item->self_drive) || ($mode === 'with_driver' && ! $item->with_driver)) {
                throw ValidationException::withMessages(['drive_mode' => $mode === 'self_drive' ? 'This car is offered with a driver only.' : 'This car is offered for self-drive only.']);
            }
        }
        if ($item instanceof Trip) {
            $tripDate = filled($data['departure_id'] ?? null) ? TripDate::where('trip_id', $item->id)->find($data['departure_id']) : null;
            if (! $tripDate || ! $tripDate->is_available || $tripDate->date->isBefore(today())) {
                throw ValidationException::withMessages(['departure_id' => 'Please choose an available trip date.']);
            }
            $start = $tripDate->date->copy()->setTimeFromTimeString((string) ($tripDate->start_time ?? $item->start_time ?? '00:00'));
        }
        if ($item instanceof Package && $item->usesDepartures()) {
            $departure = filled($data['departure_id'] ?? null) ? PackageDeparture::with('package')->where('package_id', $item->id)->find($data['departure_id']) : null;
            if (! $departure || ! $departure->isBookable()) {
                throw ValidationException::withMessages(['departure_id' => 'Please choose an available departure.']);
            }
        }

        $quote = PriceEngine::calculate([
            'service_type' => $service->value,
            'item' => $item,
            'start_at' => $start,
            'end_at' => $end,
            'drive_mode' => $item instanceof Car ? ($mode ?? DriveMode::SelfDrive->value) : null,
            'guests' => $data['guests'] ?? null,
            'departure' => $departure,
            'delivery_method' => ! empty($data['delivery']) ? 'delivery' : 'office',
            'return_method' => ! empty($data['collection']) ? 'collection' : 'office',
            'extra_keys' => $data['extras'] ?? [],
        ]);

        [$available, $reason] = $this->availability($item, $start, $end, $tripDate, $departure, (int) ($data['guests'] ?? 0));

        if (! $quote) {
            return response()->json(['data' => [
                'available' => $available, 'reason' => $reason, 'price' => null,
                'note' => 'Price on request. Our team will send you a quotation.',
            ]]);
        }

        $rate = Tax::defaultRate();
        $totals = PriceEngine::totals((float) $quote['base_amount'], $quote['lines'], 0, $rate, Tax::defaultInclusive());

        return response()->json(['data' => [
            'available' => $available,
            'reason' => $reason,
            'product' => ['type' => $data['product_type'], 'slug' => $item->slug, 'name' => $item->name],
            'start_at' => $quote['start_at'],
            'end_at' => $quote['end_at'],
            'currency' => 'AED',
            'price' => [
                'base' => ['label' => $quote['base_label'], 'amount' => (float) $quote['base_amount']],
                'lines' => collect($quote['lines'])->map(fn ($l) => ['key' => $l['key'], 'label' => $l['label'], 'kind' => $l['kind'], 'amount' => (float) $l['amount']])->values()->all(),
                'subtotal' => $totals['net'],
                'vat' => $rate ? ['rate' => $rate, 'amount' => $totals['tax'], 'included' => Tax::defaultInclusive()] : null,
                'total' => $totals['total'],
            ],
            'security_deposit' => (float) ($quote['deposit'] ?? 0) ?: null,
            'conditions' => self::publicWarnings($quote['warnings'] ?? []),
            'optional_extras' => collect(PriceEngine::optionalExtras($service->value, $item))
                ->map(fn ($o, $key) => ['key' => $key, 'label' => $o['label'], 'price' => (float) $o['price'], 'unit' => $o['unit']])->values()->all(),
            'note' => 'Estimate only. Nothing is booked or held. Our team confirms availability and the final price.',
        ]]);
    }

    /** The published product by slug; anything unpublished is "not available". */
    public static function product(string $type, string $slug): Model
    {
        $item = match ($type) {
            'car' => Car::query()->publiclyVisible()->where('slug', $slug)->first(),
            'yacht' => Yacht::query()->publiclyVisible()->where('slug', $slug)->first(),
            'yacht_trip' => Trip::query()->publiclyVisible()->where('status', TripStatus::Active)->where('slug', $slug)->first(),
            'package' => Package::query()->publiclyVisible()->active()->where('slug', $slug)->first(),
            default => null,
        };
        if (! $item) {
            throw ValidationException::withMessages(['product_slug' => 'This product is not available.']);
        }

        return $item;
    }

    /** @return array{0: ?bool, 1: ?string} Free or not, with a customer-safe reason only. */
    protected function availability(Model $item, ?Carbon $start, ?Carbon $end, ?TripDate $tripDate, ?PackageDeparture $departure, int $guests): array
    {
        if ($item instanceof Car || $item instanceof Yacht) {
            if (Availability::restriction($item->getMorphClass(), $item->id)) {
                return [false, 'Not available for these dates.'];
            }
            if (Availability::assetConflict($item->getMorphClass(), $item->id, $start, $end, null, false)) {
                return [false, 'Not available for these dates.'];
            }

            return [true, null];
        }
        if ($tripDate) {
            $left = Availability::seatsLeft($tripDate);

            return $left === null || $left >= max(1, $guests) ? [true, null] : [false, $left ? "Only {$left} seat(s) left on this date." : 'This date is full.'];
        }
        if ($departure) {
            $left = Availability::packageSeatsLeft($departure);

            return $left === null || $left >= max(1, $guests) ? [true, null] : [false, $left ? "Only {$left} place(s) left on this departure." : 'This departure is full.'];
        }

        return [null, null]; // on request
    }

    /** Engine warnings meant for the customer; staff hints (price list setup) are left out. */
    public static function publicWarnings(array $warnings): array
    {
        return collect($warnings)
            ->reject(fn ($w) => str_contains($w, '›') || str_contains($w, 'price list'))
            ->map(fn ($w) => $w === 'Delivery is switched off for this car.' ? 'Delivery is not offered for this car.' : $w)
            ->values()->all();
    }
}
