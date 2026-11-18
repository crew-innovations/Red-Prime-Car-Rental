<?php

namespace App\Support\Transfers;

use App\Models\AuditLog;
use App\Models\PriceExtra;
use App\Models\TransferFixedRoute;
use App\Models\TransferRate;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 6 Nov (Limousine plan L1, decision H-46): the starter limousine list, from public competitor
 * prices (docs/PLAN-2026-11-LIMOUSINE.md §3 and §4F). Loaded only when Admin presses
 * "Load starter limousine list"; it only ADDS what is missing (matched by slug / name) and never
 * changes a service, class, rate or route that already exists. Everything stays editable.
 */
class StarterCatalog
{
    /** Places people ask for most: quick buttons on the website and ready options in the CRM. */
    public const SPOTS = ['Dubai International Airport Terminal 1', 'Dubai International Airport Terminal 3', 'Al Maktoum International Airport (DWC)', 'Abu Dhabi International Airport', 'Sharjah International Airport', 'The Dubai Mall', 'Atlantis The Palm', 'Dubai Marina'];

    public const SERVICES = [
        ['airport-pickup', 'Airport pickup', 'one_way', true, true, 'We track your flight, wait 60 minutes free after landing and meet you in arrivals with a name sign.'],
        ['airport-drop-off', 'Airport drop-off', 'one_way', true, true, 'From your hotel, home or office to any UAE airport, on time.'],
        ['point-to-point', 'Point to point', 'one_way', false, true, 'A private chauffeur from A to B, anywhere in the UAE.'],
        ['by-the-hour', 'Chauffeur by the hour', 'hourly', false, false, 'Your chauffeur stays with you: meetings, shopping, city tour. Minimum 3 hours; 5 and 10 hour packages.'],
        ['city-to-city', 'City to city', 'one_way', false, true, 'Dubai, Abu Dhabi, Sharjah, Ras Al Khaimah and more, at a fixed price.'],
        ['event-wedding', 'Events & weddings', 'hourly', false, false, 'Luxury cars for weddings, galas and VIP guests, decorated on request.'],
    ];

    /** [slug, name, seats, bags, examples, start, per km, minimum, per hour, 5 h, 10 h] */
    public const CLASSES = [
        ['business-sedan', 'Business sedan', 3, 2, 'Mercedes E-Class, BMW 5 Series, Lexus ES or similar', 50, 4.50, 150, 120, 500, 900],
        ['first-class', 'First class', 3, 2, 'Mercedes S-Class, BMW 7 Series or similar', 80, 7.00, 300, 200, 850, 1500],
        ['luxury-suv', 'Luxury SUV', 6, 5, 'Cadillac Escalade, GMC Yukon Denali or similar', 80, 6.00, 250, 180, 750, 1350],
        ['business-van', 'Business van', 6, 6, 'Mercedes V-Class or similar', 70, 5.50, 220, 160, 700, 1250],
        ['rolls-royce', 'Rolls-Royce', 3, 2, 'Rolls-Royce Ghost or Cullinan', 200, 15.00, 800, 600, 2500, 4500],
        ['minibus', 'Minibus', 13, 13, 'Mercedes Sprinter, 12 to 14 seats', 100, 6.00, 350, 200, 900, 1600],
    ];

    /** City to city: [name, from, to, [sedan, first, suv, van]] */
    public const ROUTES = [
        ['Dubai ↔ Abu Dhabi', 'Dubai', 'Abu Dhabi', [450, 750, 650, 600]],
        ['Dubai ↔ Sharjah', 'Dubai', 'Sharjah', [150, 250, 220, 200]],
        ['Dubai ↔ Ras Al Khaimah', 'Dubai', 'Ras Al Khaimah', [450, 750, 650, 600]],
    ];

    /** @return array{services: int, classes: int, rates: int, routes: int, extras: int} */
    public static function load(): array
    {
        $n = ['services' => 0, 'classes' => 0, 'rates' => 0, 'routes' => 0, 'extras' => 0];

        DB::transaction(function () use (&$n) {
            foreach (self::SERVICES as $i => [$slug, $name, $journey, $airport, $dest, $about]) {
                $s = TransferService::withTrashed()->firstOrNew(['slug' => $slug]);
                if (! $s->exists) {
                    $s->fill(['name' => $name, 'journey_type' => $journey, 'is_airport' => $airport, 'needs_destination' => $dest,
                        'description' => $about, 'is_active' => true, 'is_published' => true, 'sort_order' => 10 + $i])->save();
                    $n['services']++;
                }
            }

            $byClass = [];
            foreach (self::CLASSES as $i => [$slug, $name, $seats, $bags, $examples, $start, $km, $min, $hour, $h5, $h10]) {
                $c = VehicleCategory::withTrashed()->where('slug', $slug)->orWhere('name', $name)->first();
                if (! $c) {
                    $c = VehicleCategory::create(['name' => $name, 'slug' => $slug, 'passengers' => $seats, 'luggage' => $bags,
                        'description' => $examples, 'is_active' => true, 'is_published' => true, 'sort_order' => 10 + $i]);
                    $n['classes']++;
                }
                $byClass[$slug] = $c;
                if (! TransferRate::query()->where('vehicle_category_id', $c->id)->exists()) {
                    TransferRate::create([
                        'vehicle_category_id' => $c->id, 'transfer_service_id' => null, 'name' => $name.' (starter)',
                        'base_fee' => $start, 'per_km' => $km, 'min_fare' => $min,
                        'per_hour' => $hour, 'min_hours' => 3, 'half_day_price' => $h5, 'half_day_hours' => 5, 'full_day_price' => $h10, 'full_day_hours' => 10,
                        'airport_fee' => 25, 'per_stop' => 30, 'free_waiting_minutes' => 15, 'waiting_per_hour' => 60,
                        'night_percent' => 0, 'return_discount_percent' => 10, 'toll_fee' => null,
                        'starts_on' => today(), 'is_active' => true,
                    ]);
                    $n['rates']++;
                }
            }

            $four = ['business-sedan', 'first-class', 'luxury-suv', 'business-van'];
            foreach (self::ROUTES as [$name, $from, $to, $prices]) {
                foreach ($four as $k => $slug) {
                    $c = $byClass[$slug] ?? null;
                    if ($c && ! TransferFixedRoute::query()->where('vehicle_category_id', $c->id)->where('from_match', $from)->where('to_match', $to)->exists()) {
                        TransferFixedRoute::create(['vehicle_category_id' => $c->id, 'name' => $name, 'from_match' => $from, 'to_match' => $to,
                            'both_ways' => true, 'price' => $prices[$k], 'is_active' => true]);
                        $n['routes']++;
                    }
                }
            }

            foreach ([['Child seat', 50, 'For children up to 4 years. Tell us the age.'], ['Booster seat', 30, 'For children 4 to 10 years.'], ['Flowers & car decoration', 350, 'For weddings and special days.']] as $i => [$name, $price, $about]) {
                if (! PriceExtra::query()->where('name', $name)->exists()) {
                    PriceExtra::create(['name' => $name, 'kind' => 'extra', 'service_types' => ['transfer'], 'unit' => 'per_booking',
                        'amount' => $price, 'is_active' => true, 'sort_order' => 50 + $i, 'description' => $about]);
                    $n['extras']++;
                }
            }

            AuditLog::create(['user_id' => auth()->id(), 'event' => 'settings', 'new_values' => $n,
                'note' => 'Loaded the starter limousine list (H-46): '.collect($n)->map(fn ($v, $k) => $v.' '.$k)->implode(', ')]);
        });

        return $n;
    }
}
