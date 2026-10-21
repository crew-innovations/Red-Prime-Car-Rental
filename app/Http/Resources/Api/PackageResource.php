<?php

namespace App\Http\Resources\Api;

use App\Models\Package;
use App\Models\PackageDeparture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a Trip / Package, including the day-by-day itinerary.
 * No notes, bookings, customers or internal settings.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Package $package */
        $package = $this->resource;
        $detail = (bool) ($this->additional['detail'] ?? false);
        $cover = Media::image($package->getFirstMedia('cover')) ?? (Media::collection($package, 'gallery')[0] ?? null);

        return array_filter([
            'id' => $package->id,
            'type' => 'package',
            'slug' => $package->slug,
            'name' => $package->name,
            'category' => $package->category ? ['slug' => $package->category->slug, 'name' => $package->category->name] : null,
            'product_type' => $package->product_type,
            'badge' => $package->website_badge,
            'featured' => (bool) $package->is_featured,
            'summary' => $package->summary,
            'description_html' => $detail ? $package->description : null,
            'duration' => ['days' => $package->duration_days, 'nights' => $package->duration_nights, 'label' => $package->durationLabel()],
            'destinations' => array_values((array) $package->destinations),
            'start_location' => $package->start_location,
            'meeting_point' => $detail ? $package->meeting_point : null,
            'booking_mode' => $package->booking_mode, // departures = fixed dates, on_request = customer picks a date (confirmed by staff)
            'booking_cutoff_days' => $package->booking_cutoff_days,
            'guests' => ['min' => $package->min_guests, 'max' => $package->max_guests],
            'price' => [
                'unit' => $package->isPerPerson() ? 'per_person' : 'per_package',
                'amount' => $package->price !== null ? (float) $package->price : null,
                'sale_amount' => $package->sale_price !== null ? (float) $package->sale_price : null,
                'from_amount' => $package->unitPrice(),
                'child_amount' => $package->isPerPerson() && $package->child_price !== null ? (float) $package->child_price : null,
                'currency' => $package->currency ?: 'AED',
                'group_prices' => $package->isPerPerson()
                    ? collect((array) $package->price_tiers)->filter(fn ($t) => isset($t['min_guests'], $t['price']))->map(fn ($t) => ['min_guests' => (int) $t['min_guests'], 'amount' => (float) $t['price']])->sortBy('min_guests')->values()->all()
                    : [],
            ],
            'itinerary' => $detail ? collect((array) $package->itinerary)->values()->map(fn ($day, $i) => [
                'day' => $i + 1,
                'title' => $day['title'] ?? null,
                'description' => $day['description'] ?? null,
                'destinations' => array_values((array) ($day['destinations'] ?? [])),
                'activities' => array_values((array) ($day['activities'] ?? [])),
                'included' => array_values((array) ($day['included'] ?? [])),
                'meals' => array_values((array) ($day['meals'] ?? [])),
                'accommodation' => $day['accommodation'] ?? null,
                'image' => Media::path(is_array($day['image'] ?? null) ? (array_values($day['image'])[0] ?? null) : ($day['image'] ?? null)),
            ])->all() : null,
            'itinerary_days' => count((array) $package->itinerary),
            'inclusions' => array_values((array) $package->inclusions),
            'exclusions' => $detail ? array_values((array) $package->exclusions) : null,
            'activities' => array_values((array) $package->activities),
            'accommodation' => $detail ? $package->accommodation : null,
            'meals' => $detail ? $package->meals : null,
            'transport' => $detail ? $package->transport : null,
            'requirements' => $detail ? $package->requirements : null,
            'terms' => $detail ? $package->terms : null,
            'images' => [
                'cover' => $cover,
                'gallery' => $detail ? Media::collection($package, 'gallery') : null,
            ],
            'next_departure' => $package->usesDepartures() && ($next = $package->upcomingDepartures->first(fn (PackageDeparture $d) => $d->isBookable()))
                ? self::departure($next, $package) : null,
            'departures' => $detail && $package->usesDepartures()
                ? $package->upcomingDepartures->filter(fn (PackageDeparture $d) => $d->isBookable())->take(60)->map(fn (PackageDeparture $d) => self::departure($d, $package))->values()->all()
                : null,
            'seo' => $detail ? ['title' => $package->meta_title ?: $package->name, 'description' => $package->meta_description ?: $package->summary] : null,
        ], fn ($v) => $v !== null);
    }

    public static function departure(PackageDeparture $departure, Package $package): array
    {
        $departure->setRelation('package', $package);
        $seats = $departure->seats();
        $left = $seats === null ? null : max(0, $seats - $departure->bookedGuests());

        return [
            'id' => $departure->id,
            'start_date' => $departure->start_date->toDateString(),
            'end_date' => $departure->end_date?->toDateString(),
            'start_time' => $departure->start_time ? substr($departure->start_time, 0, 5) : null,
            'price' => $departure->price !== null ? (float) $departure->price : $package->unitPrice(),
            'seats_left' => $left,
            'available' => $left === null || $left > 0,
        ];
    }
}
