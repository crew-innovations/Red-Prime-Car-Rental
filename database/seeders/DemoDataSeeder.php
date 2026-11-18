<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\CarStatus;
use App\Enums\CustomerType;
use App\Enums\LeadPriority;
use App\Enums\Pipeline;
use App\Enums\ServiceType;
use App\Enums\TripStatus;
use App\Enums\YachtStatus;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use Illuminate\Database\Seeder;

/**
 * Sample data for demos and testing (no photos). Run on a fresh database only:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        $sales = User::firstOrCreate(
            ['email' => 'sales@redprime.test'],
            ['name' => 'Sara (Agent)', 'password' => 'change-me', 'is_active' => true],
        );
        $sales->assignRole('Agent');

        $cars = [
            ['Lamborghini Urus 2024', 'Lamborghini', 'Urus', 'SUV', 3500, CarStatus::Available, 'Best Seller', '4.0L V8 Twin Turbo', 657],
            ['Rolls-Royce Cullinan', 'Rolls-Royce', 'Cullinan', 'Luxury', 5800, CarStatus::Rented, null, '6.75L V12', 563],
            ['Ferrari 296 GTB', 'Ferrari', '296 GTB', 'Supercar', 4900, CarStatus::Available, 'New', '3.0L V6 Hybrid', 819],
            ['Mercedes-Benz G63 AMG', 'Mercedes-Benz', 'G63', 'SUV', 2750, CarStatus::Reserved, 'Most Popular', '4.0L V8 Biturbo', 577],
            ['Porsche 911 Carrera S', 'Porsche', '911', 'Sports', 2200, CarStatus::Maintenance, null, '3.0L Flat-6', 443],
            ['Range Rover Sport', 'Land Rover', 'Range Rover Sport', 'SUV', 1600, CarStatus::Available, null, '3.0L I6', 395],
        ];

        foreach ($cars as $i => [$name, $brand, $model, $type, $daily, $status, $badge, $engine, $hp]) {
            Car::firstOrCreate(['name' => $name], [
                'brand' => $brand, 'model' => $model, 'year' => 2024, 'type' => $type,
                'transmission' => 'Automatic', 'fuel' => $engine === '3.0L V6 Hybrid' ? 'Hybrid' : 'Petrol',
                'engine' => $engine, 'horsepower' => $hp, 'seats' => in_array($type, ['Sports', 'Supercar']) ? 2 : 5,
                'doors' => in_array($type, ['Sports', 'Supercar']) ? 2 : 4, 'luggage' => 3,
                'daily_price' => $daily, 'weekly_price' => $daily * 6, 'deposit' => 5000,
                'min_rental_days' => 1, 'min_driver_age' => 25, 'daily_km_limit' => 250, 'extra_km_charge' => 5,
                'salik_charge' => 5, 'insurance_type' => 'comprehensive', 'branch' => 'Dubai Marina',
                'status' => $status, 'is_published' => $status !== CarStatus::Maintenance, 'is_featured' => $i < 2,
                'website_badge' => $badge,
                'registration_expiry' => today()->addMonths(6 + $i),
                'insurance_expiry' => $i === 1 ? today()->addDays(12) : today()->addMonths(8),
                'next_service_date' => $i === 4 ? today()->subDays(3) : today()->addMonths(2),
                'features' => ['Apple CarPlay', '360° Camera', 'Leather Seats'],
            ]);
        }

        $yacht = Yacht::firstOrCreate(['name' => 'Azimut 68 "Ocean Pearl"'], [
            'type' => 'Motor Yacht', 'manufacturer' => 'Azimut', 'model' => '68', 'length_ft' => 68,
            'max_guests' => 25, 'capacity' => 30, 'cabins' => 3, 'bathrooms' => 3, 'crew_count' => 3,
            'marina' => 'Dubai Marina', 'hourly_price' => 1200, 'sunset_price' => 2200, 'half_day_price' => 4400,
            'full_day_price' => 8500, 'minimum_hours' => 2, 'status' => YachtStatus::Available,
            'is_published' => true, 'is_featured' => true, 'website_badge' => 'Party Ready',
            'amenities' => ['Sundeck', 'Flybridge', 'Sound System', 'BBQ Grill'],
            'license_expiry' => today()->addMonths(5), 'insurance_expiry' => today()->addMonths(9),
        ]);

        Yacht::firstOrCreate(['name' => 'Majesty 56 "Blue Horizon"'], [
            'type' => 'Motor Yacht', 'manufacturer' => 'Majesty', 'model' => '56', 'length_ft' => 56,
            'max_guests' => 18, 'cabins' => 2, 'crew_count' => 2, 'marina' => 'Dubai Harbour',
            'hourly_price' => 850, 'full_day_price' => 6000, 'minimum_hours' => 2,
            'status' => YachtStatus::Booked, 'is_published' => true,
        ]);

        $trip = Trip::firstOrCreate(['name' => 'Dubai Marina Sunset Cruise'], [
            'yacht_id' => $yacht->id, 'category' => 'Sunset Cruise', 'location' => 'Dubai Marina, Palm Jumeirah, Atlantis',
            'departure_marina' => 'Dubai Marina', 'duration_minutes' => 120, 'start_time' => '17:30',
            'guest_capacity' => 25, 'price' => 299, 'child_price' => 199, 'price_unit' => 'per_person',
            'status' => TripStatus::Active, 'is_published' => true, 'is_featured' => true,
            'included_services' => ['Soft drinks & water', 'Snacks', 'Swimming stop'],
            'excluded_services' => ['Alcoholic drinks', 'Hotel transfer'],
            'itinerary' => [['time' => '17:30', 'title' => 'Boarding at Dubai Marina'], ['time' => '18:15', 'title' => 'Sunset at Atlantis']],
        ]);

        if ($trip->dates()->doesntExist()) {
            foreach (range(1, 10) as $d) {
                $trip->dates()->create(['date' => today()->addDays($d), 'start_time' => '17:30', 'available_slots' => 25]);
            }
        }

        $source = fn (string $slug) => LeadSource::where('slug', $slug)->value('id');

        $customer = Customer::firstOrCreate(['phone' => '+971501112233'], [
            'name' => 'Ahmed Al Mansoori', 'whatsapp' => '+971501112233', 'email' => 'ahmed@example.com',
            'nationality' => 'Emirati', 'residency' => 'resident', 'language' => 'Arabic',
            'type' => CustomerType::Individual, 'is_vip' => true, 'tags' => ['Repeat customer'],
            'lead_source_id' => $source('instagram'), 'assigned_to' => $sales->id,
        ]);

        $car = fn (string $brand) => Car::where('brand', $brand)->first();

        // [name, phone, source, service, item, pipeline, stage, priority, follow-up in days, budget, company]
        $leads = [
            ['Olga Petrova', '+971559876543', 'instagram', ServiceType::Car, $car('Lamborghini'), Pipeline::Car, 'new', LeadPriority::Hot, 0, 10500, null],
            ['Daniel Novak', '+420777123456', 'tiktok', ServiceType::Car, $car('Ferrari'), Pipeline::Car, 'contacted', LeadPriority::Warm, 1, 9800, null],
            ['Sara Haddad', '+971585551212', 'website', ServiceType::Car, $car('Porsche'), Pipeline::Car, 'car_selected', LeadPriority::Warm, 2, 6600, null],
            ['Luca Romano', '+393401234567', 'google-ads-search', ServiceType::Car, $car('Mercedes-Benz'), Pipeline::Car, 'quotation_sent', LeadPriority::Hot, -1, 8250, null],
            ['Fatima Zahra', '+971506667788', 'whatsapp', ServiceType::Car, $car('Land Rover'), Pipeline::Car, 'documents_received', LeadPriority::Warm, 1, 4800, null],
            ['Mohammed Khan', '+971567654321', 'google-ads-search', ServiceType::Car, $car('Ferrari'), Pipeline::Car, Pipeline::LOST, LeadPriority::Cold, null, null, null],
            ['Ahmed Al Mansoori', '+971501112233', 'instagram', ServiceType::Car, $car('Rolls-Royce'), Pipeline::Car, 'vehicle_delivered', LeadPriority::Warm, null, 17400, null],
            ['James Carter', '+447700900123', 'website', ServiceType::Yacht, $yacht, Pipeline::Yacht, 'requirements_confirmed', LeadPriority::Warm, -1, 6000, null],
            ['Elena Ivanova', '+79161234567', 'instagram', ServiceType::Yacht, $yacht, Pipeline::Yacht, 'quotation_sent', LeadPriority::Hot, 1, 7200, null],
            ['Priya Sharma', '+971521234567', 'whatsapp', ServiceType::Trip, $trip, Pipeline::Yacht, 'availability_confirmed', LeadPriority::Hot, 1, 1200, null],
            ['Omar Siddiqui', '+971504443322', 'corporate-partner', null, null, Pipeline::B2b, 'meeting', LeadPriority::Hot, 2, 60000, 'Atlas Events LLC'],
            ['Rachel Green', '+971555009988', 'concierge-hotel', null, null, Pipeline::B2b, 'proposal', LeadPriority::Warm, 3, 25000, 'Palm Bay Hotel Concierge'],
        ];

        foreach ($leads as [$name, $phone, $src, $service, $item, $pipeline, $stage, $priority, $followUpDays, $budget, $company]) {
            $lead = Lead::firstOrCreate(['phone' => $phone], [
                'name' => $name, 'company_name' => $company, 'lead_source_id' => $source($src) ?? $source('other'),
                'service_type' => $service, 'pipeline' => $pipeline, 'stage' => $stage, 'priority' => $priority,
                'interested_in_type' => $item?->getMorphClass(), 'interested_in_id' => $item?->getKey(),
                'preferred_date' => today()->addDays(5), 'assigned_to' => $sales->id, 'budget' => $budget,
                'next_follow_up_at' => $followUpDays === null ? null : now()->addDays($followUpDays),
                'lost_reason' => $stage === Pipeline::LOST ? 'Price too high' : null,
                'customer_id' => $name === 'Ahmed Al Mansoori' ? $customer->id : null,
                'message' => $pipeline === Pipeline::B2b
                    ? 'We need cars and a yacht for corporate guests every month. Can we discuss a partner rate?'
                    : 'Hi, is this available next week? Please share the best price.',
            ]);

            if ($stage !== 'new' && $lead->activities()->doesntExist()) {
                $lead->activities()->create([
                    'type' => ActivityType::WhatsApp, 'outcome' => 'interested', 'user_id' => $sales->id,
                    'body' => 'Shared prices and photos on WhatsApp.',
                ]);
            }
        }

        $this->call(DemoAccountsSeeder::class);
        $this->call(DemoBookingsSeeder::class);
        $this->call(DemoQuotationsSeeder::class);
        $this->call(DemoPackagesSeeder::class);
    }
}
