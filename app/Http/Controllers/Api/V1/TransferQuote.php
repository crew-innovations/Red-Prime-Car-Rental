<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Car;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use App\Support\Availability;
use App\Support\Maps\GoogleMaps;
use App\Support\Pricing\TransferPricing;
use App\Support\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Website limousine / transfer quote (brief 28 Oct-2, 4.5 and 16). The route is
 * worked out by the CRM with Google Maps; a distance sent by the browser is never
 * used. Without Google Maps set up, the answer is "price on request".
 * Nothing is booked or held.
 */
class TransferQuote
{
    public static function rules(): array
    {
        return [
            'product_slug' => ['required', 'string', 'max:255'],           // vehicle category slug
            'service' => ['required', 'string', 'max:255'],                // transfer service slug
            'pickup' => ['required', 'string', 'min:3', 'max:255'],
            'dropoff' => ['nullable', 'string', 'min:3', 'max:255'],
            'stops' => ['sometimes', 'array', 'max:5'],
            'stops.*' => ['string', 'min:3', 'max:255'],
            'start_at' => ['required', 'date_format:Y-m-d H:i', 'after_or_equal:today'],
            'end_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'return_at' => ['nullable', 'date_format:Y-m-d H:i', 'after:start_at'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:60'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];
    }

    /** @return array{0: TransferService, 1: VehicleCategory} */
    public static function resolve(string $serviceSlug, string $categorySlug): array
    {
        $service = TransferService::query()->where('is_active', true)->where('is_published', true)->where('slug', $serviceSlug)->first();
        $category = VehicleCategory::query()->where('is_active', true)->where('is_published', true)->where('slug', $categorySlug)->first();
        if (! $service) {
            throw ValidationException::withMessages(['service' => 'This service is not available.']);
        }
        if (! $category) {
            throw ValidationException::withMessages(['product_slug' => 'This vehicle category is not available.']);
        }

        return [$service, $category];
    }

    public static function quote(Request $request): array
    {
        $data = $request->validate(self::rules());
        [$service, $category] = self::resolve($data['service'], $data['product_slug']);
        $start = Carbon::parse($data['start_at']);
        $hourly = $service->isHourly();
        if (! $hourly && $service->needs_destination && blank($data['dropoff'] ?? null)) {
            throw ValidationException::withMessages(['dropoff' => 'Enter the destination.']);
        }
        if ($hourly && blank($data['end_at'] ?? null)) {
            throw ValidationException::withMessages(['end_at' => 'Choose until when you need the chauffeur.']);
        }
        if ($category->passengers && (int) ($data['passengers'] ?? 0) > $category->passengers) {
            throw ValidationException::withMessages(['passengers' => "{$category->name} takes up to {$category->passengers} passengers."]);
        }

        $base = [
            'product' => ['type' => 'transfer', 'service' => $service->slug, 'category' => $category->slug, 'name' => $service->name.' · '.$category->name],
            'start_at' => $start->toDateTimeString(), 'currency' => 'AED',
        ];

        // Road route from the CRM (never from the browser).
        $route = null;
        if (! $hourly) {
            if (! GoogleMaps::ready()) {
                return $base + ['available' => null, 'reason' => null, 'price' => null, 'note' => 'Price on request. Our team will send you a quotation.'];
            }
            try {
                $route = GoogleMaps::route($data['pickup'], (string) ($data['dropoff'] ?? ''), (array) ($data['stops'] ?? []), $start);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['pickup' => 'We could not find a road route for these addresses. Please check them.']);
            }
        }
        $returnKm = null;
        $returnAt = filled($data['return_at'] ?? null) ? Carbon::parse($data['return_at']) : null;
        if ($returnAt && $route) {
            $returnKm = rescue(fn () => GoogleMaps::route((string) $data['dropoff'], $data['pickup'], array_reverse((array) ($data['stops'] ?? [])), $returnAt)['km'], $route['km'], false);
        }

        $minutes = $route['minutes'] ?? null;
        $end = $hourly ? Carbon::parse($data['end_at']) : ($returnAt ?? $start)->copy()->addMinutes(max(30, (int) ($minutes ?: 60)));
        $quote = TransferPricing::calculate([
            'transfer_service' => $service, 'vehicle_category' => $category, 'start_at' => $start, 'end_at' => $end,
            'journey_type' => $hourly ? 'hourly' : ($returnAt ? 'return' : $service->journey_type),
            'route_km' => $route['km'] ?? null, 'return_km' => $returnKm, 'route_minutes' => $minutes, 'route_source' => $route ? 'google' : null,
            'stops' => (array) ($data['stops'] ?? []), 'return_at' => $returnAt, 'guests' => $data['passengers'] ?? null,
            'pickup_location' => $data['pickup'], 'dropoff_location' => $data['dropoff'] ?? null,
        ]);

        // Free if at least one car of the category is free for the whole job (no booking details shown).
        $cars = Car::query()->where('vehicle_category_id', $category->id)->pluck('id');
        $available = $cars->isEmpty() ? null : $cars->contains(fn ($id) => ! Availability::restriction('car', $id) && ! Availability::assetConflict('car', $id, $start, $end, null, false));

        if (! $quote) {
            return $base + ['available' => $available, 'reason' => $available === false ? 'Not available at this time.' : null, 'price' => null,
                'route' => $route ? ['km' => $route['km'], 'minutes' => $route['minutes']] : null, 'note' => 'Price on request. Our team will send you a quotation.'];
        }
        $rate = Tax::defaultRate();
        $totals = \App\Support\Pricing\PriceEngine::totals((float) $quote['base_amount'], $quote['lines'], 0, $rate, Tax::defaultInclusive());

        return $base + [
            'available' => $available,
            'reason' => $available === false ? 'Not available at this time.' : null,
            'route' => $route ? ['km' => $route['km'], 'minutes' => $route['minutes'], 'return_km' => $returnKm] : null,
            'price' => [
                'base' => ['label' => $quote['base_label'], 'amount' => (float) $quote['base_amount']],
                'lines' => collect($quote['lines'])->map(fn ($l) => ['key' => $l['key'], 'label' => $l['label'], 'kind' => $l['kind'], 'amount' => (float) $l['amount']])->values()->all(),
                'subtotal' => $totals['net'],
                'vat' => $rate ? ['rate' => $rate, 'amount' => $totals['tax'], 'included' => Tax::defaultInclusive()] : null,
                'total' => $totals['total'],
            ],
            'conditions' => PriceEstimateController::publicWarnings($quote['warnings'] ?? []),
            'note' => 'Estimate only. Nothing is booked or held. Our team confirms availability and the final price.',
        ];
    }
}
