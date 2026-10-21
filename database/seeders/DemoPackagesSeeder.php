<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\PackageCategory;
use Illuminate\Database\Seeder;

/** Two sample Trips & Packages (demo data only; safe to run twice). */
class DemoPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $safaris = PackageCategory::firstOrCreate(['slug' => 'desert-safaris'], ['name' => 'Desert Safaris', 'sort_order' => 1]);
        $day = PackageCategory::firstOrCreate(['slug' => 'day-trips'], ['name' => 'Day Trips', 'sort_order' => 2]);

        $liwa = Package::firstOrCreate(['slug' => 'liwa-desert-safari-3-days'], [
            'name' => '3-Day Liwa Desert Safari',
            'package_category_id' => $safaris->id,
            'product_type' => 'Desert safari',
            'summary' => 'Three days in the Empty Quarter: dunes, camel trek, stargazing and a night at a desert camp.',
            'description' => '<p>Leave the city behind for the dunes of Liwa, some of the tallest in the world.</p>',
            'duration_days' => 3,
            'duration_nights' => 2,
            'destinations' => ['Dubai', 'Liwa'],
            'start_location' => 'Dubai (hotel pick-up)',
            'meeting_point' => 'Hotel lobby, 07:00',
            'booking_mode' => 'departures',
            'booking_cutoff_days' => 3,
            'min_guests' => 1,
            'max_guests' => 8,
            'default_capacity' => 12,
            'pricing_mode' => 'per_person',
            'price' => 2450,
            'price_tiers' => [['min_guests' => 4, 'price' => 2200]],
            'itinerary' => [
                ['title' => 'Dubai to Liwa', 'description' => 'Pick-up, drive to Liwa, sunset on the dunes.', 'activities' => ['Dune bashing'], 'meals' => ['lunch', 'dinner'], 'accommodation' => 'Desert camp'],
                ['title' => 'The Empty Quarter', 'description' => 'Moreeb Dune, camel trek and stargazing.', 'activities' => ['Camel ride', 'Stargazing'], 'meals' => ['breakfast', 'lunch', 'dinner'], 'accommodation' => 'Desert camp'],
                ['title' => 'Back to Dubai', 'description' => 'Sunrise walk, breakfast and drive back.', 'activities' => ['Sandboarding'], 'meals' => ['breakfast']],
            ],
            'inclusions' => ['Hotel pick-up & drop-off', '4x4 transport', 'All meals', 'Desert camp'],
            'exclusions' => ['Tips', 'Personal expenses'],
            'activities' => ['Dune bashing', 'Camel ride', 'Sandboarding', 'Stargazing'],
            'terms' => 'Free cancellation up to 7 days before departure.',
            'is_published' => true,
            'is_featured' => true,
        ]);
        if (! $liwa->departures()->exists()) {
            foreach ([7, 14, 21] as $days) {
                $start = today()->addDays($days);
                $liwa->departures()->create(['start_date' => $start, 'end_date' => $start->copy()->addDays(2), 'start_time' => '07:00', 'is_available' => true]);
            }
        }

        Package::firstOrCreate(['slug' => 'hatta-mountain-day-trip'], [
            'name' => 'Hatta Mountain Day Trip',
            'package_category_id' => $day->id,
            'product_type' => 'One-day trip',
            'summary' => 'Kayaking at Hatta Dam, the heritage village and mountain views, back by evening.',
            'duration_days' => 1,
            'destinations' => ['Hatta'],
            'start_location' => 'Dubai',
            'booking_mode' => 'on_request',
            'booking_cutoff_days' => 1,
            'pricing_mode' => 'fixed',
            'price' => 1800,
            'max_guests' => 6,
            'itinerary' => [['title' => 'Hatta day', 'description' => 'Kayaking, heritage village, lunch, drive back.', 'activities' => ['Kayaking'], 'meals' => ['lunch']]],
            'inclusions' => ['Private car & driver', 'Kayak rental', 'Lunch'],
            'is_published' => true,
        ]);
    }
}
