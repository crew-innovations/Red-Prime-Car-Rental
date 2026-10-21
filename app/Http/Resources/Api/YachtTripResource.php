<?php

namespace App\Http\Resources\Api;

use App\Models\Trip;
use App\Models\TripDate;
use App\Support\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a Yacht Trip. Only intentionally public fields: no notes,
 * operator, bookings, customers or internal settings.
 *
 * @mixin Trip
 */
class YachtTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Trip $trip */
        $trip = $this->resource;
        $detail = (bool) ($this->additional['detail'] ?? false);
        $gallery = Media::collection($trip, 'gallery');

        return array_filter([
            'id' => $trip->id,
            'type' => 'yacht_trip',
            'slug' => $trip->slug,
            'name' => $trip->name,
            'category' => $trip->category,
            'badge' => $trip->website_badge,
            'featured' => (bool) $trip->is_featured,
            'summary' => \Illuminate\Support\Str::limit(trim(strip_tags((string) $trip->description)), 220),
            'description_html' => $detail ? $trip->description : null,
            'duration_minutes' => $trip->duration_minutes,
            'start_time' => $trip->start_time ? substr($trip->start_time, 0, 5) : null,
            'location' => $trip->location,
            'departure_marina' => $trip->departure_marina,
            'meeting_point' => $trip->meeting_point,
            'price' => [
                'amount' => $trip->price !== null ? (float) $trip->price : null,
                'child_amount' => $trip->child_price !== null ? (float) $trip->child_price : null,
                'currency' => 'AED',
                'unit' => $trip->price_unit === 'per_trip' ? 'per_trip' : 'per_person',
            ],
            'guests' => ['min' => $trip->min_guests, 'max' => $trip->guest_capacity],
            'included' => array_values((array) $trip->included_services),
            'excluded' => array_values((array) $trip->excluded_services),
            'itinerary' => $detail ? collect((array) $trip->itinerary)->map(fn ($s) => ['time' => $s['time'] ?? null, 'title' => $s['title'] ?? null])->values()->all() : null,
            'add_ons' => $detail ? collect((array) $trip->addons)->map(fn ($a) => ['name' => $a['name'] ?? null, 'price' => isset($a['price']) ? (float) $a['price'] : null, 'unit' => $a['unit'] ?? null])->values()->all() : null,
            'mandatory_charges' => $detail ? collect((array) $trip->additional_charges)->map(fn ($c) => ['name' => $c['name'] ?? null, 'amount' => isset($c['amount']) ? (float) $c['amount'] : null, 'unit' => $c['unit'] ?? null])->values()->all() : null,
            'requirements' => $detail ? $trip->booking_requirements : null,
            'instructions' => $detail ? $trip->customer_instructions : null,
            'terms' => $detail ? $trip->cancellation_terms : null,
            'images' => [
                'cover' => $gallery[0] ?? null,
                'gallery' => $detail ? $gallery : null,
            ],
            'next_departure' => ($next = $trip->upcomingDates->first()) ? self::departure($next, $trip) : null,
            'departures' => $detail ? $trip->upcomingDates->take(60)->map(fn (TripDate $d) => self::departure($d, $trip))->values()->all() : null,
            'seo' => $detail ? ['title' => $trip->meta_title ?: $trip->name, 'description' => $trip->meta_description] : null,
        ], fn ($v) => $v !== null);
    }

    public static function departure(TripDate $date, Trip $trip): array
    {
        $date->setRelation('trip', $trip);
        $seats = $date->available_slots ?? $trip->guest_capacity;
        $left = $seats === null ? null : max(0, (int) $seats - $date->bookedGuests());

        return [
            'id' => $date->id,
            'date' => $date->date->toDateString(),
            'start_time' => ($t = $date->start_time ?? $trip->start_time) ? substr($t, 0, 5) : null,
            'seats_left' => $left,
            'available' => $date->is_available && ($left === null || $left > 0),
        ];
    }
}
