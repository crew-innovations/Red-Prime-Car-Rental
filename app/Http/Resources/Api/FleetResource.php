<?php

namespace App\Http\Resources\Api;

use App\Models\Car;
use App\Models\Yacht;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Public view of a car or a yacht for the website (28 Oct brief, Priority 6.2).
 * Only intentionally public fields: never registration, VIN, insurance,
 * partner, commission, notes, status details or bookings.
 */
class FleetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $detail = (bool) ($this->additional['detail'] ?? false);
        $gallery = Media::collection($item, 'gallery');
        $list = fn ($v) => collect((array) $v)->map(fn ($x) => is_array($x) ? ($x['name'] ?? $x['label'] ?? $x['value'] ?? null) : $x)->filter()->values()->all();
        $specs = fn ($v) => collect((array) $v)->map(fn ($x, $k) => is_array($x) ? ['label' => $x['label'] ?? $x['key'] ?? null, 'value' => $x['value'] ?? null] : ['label' => is_string($k) ? $k : null, 'value' => $x])
            ->filter(fn ($x) => filled($x['label']) && filled($x['value']))->values()->all();
        $addons = fn ($v) => collect((array) $v)->map(fn ($a) => ['name' => $a['name'] ?? null, 'price' => isset($a['price']) ? (float) $a['price'] : null, 'unit' => $a['unit'] ?? null])->filter(fn ($a) => filled($a['name']))->values()->all();
        $money = fn ($v) => $v !== null ? (float) $v : null;

        $common = [
            'id' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'badge' => $item->website_badge,
            'featured' => (bool) $item->is_featured,
            'summary' => Str::limit(trim(strip_tags((string) $item->description)), 220),
            'description_html' => $detail ? $item->description : null,
            'images' => ['cover' => $gallery[0] ?? null, 'gallery' => $detail ? $gallery : null],
            'deposit' => $money($item->deposit),
            'currency' => 'AED',
            'terms' => $detail ? $item->terms : null,
            'seo' => $detail ? ['title' => $item->meta_title ?: $item->name, 'description' => $item->meta_description] : null,
        ];

        if ($item instanceof Car) {
            return array_filter($common + [
                'type' => 'car',
                'brand' => $item->brand,
                'model' => $item->model,
                'year' => $item->year,
                'body' => $item->type,
                'transmission' => $item->transmission,
                'fuel' => $item->fuel,
                'seats' => $item->seats,
                'doors' => $item->doors,
                'luggage' => $item->luggage,
                'colour' => $item->colour,
                'horsepower' => $item->horsepower,
                'self_drive' => (bool) $item->self_drive,
                'with_driver' => (bool) $item->with_driver,
                'prices' => array_filter([
                    'daily' => $money($item->daily_price), 'weekly' => $money($item->weekly_price), 'monthly' => $money($item->monthly_price),
                    'hourly' => $money($item->hourly_price), 'chauffeur_daily' => $money($item->chauffeur_daily_price), 'chauffeur_hourly' => $money($item->chauffeur_hourly_price),
                ], fn ($v) => $v !== null),
                'rules' => $detail ? array_filter(['min_rental_days' => $item->min_rental_days, 'daily_km_limit' => $item->daily_km_limit, 'extra_km_charge' => $money($item->extra_km_charge),
                    'min_driver_age' => $item->min_driver_age, 'delivery_available' => (bool) $item->delivery_available, 'delivery_fee' => $money($item->delivery_fee), 'insurance' => $item->insurance_type], fn ($v) => $v !== null) : null,
                'features' => $detail ? $list($item->features) : null,
                'specifications' => $detail ? $specs($item->specifications) : null,
                'add_ons' => $detail ? $addons($item->addons) : null,
            ], fn ($v) => $v !== null);
        }

        /** @var Yacht $item */
        return array_filter($common + [
            'type' => 'yacht',
            'yacht_type' => $item->type,
            'manufacturer' => $item->manufacturer,
            'model' => $item->model,
            'year_built' => $item->year_built,
            'length_ft' => $item->length_ft,
            'guests' => $item->max_guests ?: $item->capacity,
            'cabins' => $item->cabins,
            'bathrooms' => $item->bathrooms,
            'crew' => $item->crew_count,
            'captain_included' => (bool) $item->captain_included,
            'marina' => $item->marina,
            'minimum_hours' => $item->minimum_hours,
            'fuel_included' => (bool) $item->fuel_included,
            'prices' => array_filter([
                'hourly' => $money($item->hourly_price), 'half_day' => $money($item->half_day_price),
                'full_day' => $money($item->full_day_price), 'sunset' => $money($item->sunset_price),
            ], fn ($v) => $v !== null),
            'amenities' => $detail ? $list($item->amenities) : null,
            'specifications' => $detail ? $specs($item->specifications) : null,
            'restrictions' => $detail ? $item->restrictions : null,
            'add_ons' => $detail ? $addons($item->addons) : null,
            'mandatory_charges' => $detail ? collect((array) $item->additional_charges)->map(fn ($c) => ['name' => $c['name'] ?? null, 'amount' => isset($c['amount']) ? (float) $c['amount'] : null, 'unit' => $c['unit'] ?? null])->filter(fn ($c) => filled($c['name']))->values()->all() : null,
        ], fn ($v) => $v !== null);
    }
}
