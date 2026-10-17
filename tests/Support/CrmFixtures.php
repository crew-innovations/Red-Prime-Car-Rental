<?php

namespace Tests\Support;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Trip;
use App\Models\TripDate;
use App\Models\User;
use App\Models\Yacht;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** Small helpers to build CRM records in tests. */
trait CrmFixtures
{
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
        Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'web']);
    }

    protected function user(string $role = 'Super Admin', array $attrs = []): User
    {
        $user = User::create($attrs + [
            'name' => $role.' '.Str::random(4),
            'email' => Str::random(8).'@test.local',
            'password' => Str::password(16),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function car(array $attrs = []): Car
    {
        return Car::create($attrs + [
            'name' => 'Test Car '.Str::random(4), 'brand' => 'Nissan', 'model' => 'Patrol',
            'status' => 'available', 'daily_price' => 1000, 'weekly_price' => 6000, 'monthly_price' => 20000,
            'hourly_price' => 150, 'deposit' => 2000, 'self_drive' => true, 'with_driver' => true,
            'chauffeur_daily_price' => 1800, 'chauffeur_hourly_price' => 250, 'driver_hours_per_day' => 10,
            'mileage' => 10000, 'daily_km_limit' => 250, 'extra_km_charge' => 2,
        ]);
    }

    protected function yacht(array $attrs = []): Yacht
    {
        return Yacht::create($attrs + [
            'name' => 'Test Yacht '.Str::random(4), 'status' => 'available',
            'hourly_price' => 900, 'half_day_price' => 3200, 'full_day_price' => 7000, 'deposit' => 3000, 'max_guests' => 12,
        ]);
    }

    protected function trip(?Yacht $yacht = null, array $attrs = []): Trip
    {
        return Trip::create($attrs + [
            'name' => 'Sunset Trip '.Str::random(4), 'yacht_id' => $yacht?->id, 'price' => 300, 'price_unit' => 'per_person',
            'guest_capacity' => 10, 'start_time' => '17:30', 'duration_minutes' => 120, 'status' => 'active',
        ]);
    }

    protected function tripDate(Trip $trip, $date, array $attrs = []): TripDate
    {
        return $trip->dates()->create($attrs + ['date' => Carbon::parse($date)->toDateString(), 'is_available' => true]);
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create($attrs + ['name' => 'Ahmed Test', 'phone' => '+971 50 123 4567', 'email' => Str::random(6).'@example.com']);
    }

    protected function booking(Car|Yacht|Trip $item, $start, $end, array $attrs = []): Booking
    {
        $service = match (true) {
            $item instanceof Yacht => ServiceType::Yacht,
            $item instanceof Trip => ServiceType::Trip,
            default => ServiceType::Car,
        };
        $driveMode = $attrs['drive_mode'] ?? ($service === ServiceType::Car ? DriveMode::SelfDrive : null);
        [$type, $qty, $rate, $amount] = Booking::quote($item, $start, $end, $driveMode?->value, $attrs['guests'] ?? null) ?? [null, null, null, 0];

        return Booking::create($attrs + [
            'status' => BookingStatus::Confirmed,
            'customer_id' => $attrs['customer_id'] ?? $this->customer()->id,
            'service_type' => $service,
            'bookable_id' => $item->id,
            'drive_mode' => $driveMode,
            'start_at' => Carbon::parse($start),
            'end_at' => Carbon::parse($end),
            'rate_type' => $type, 'quantity' => $qty, 'rate' => $rate, 'base_amount' => $amount,
            'security_deposit' => (float) ($item->deposit ?? 0),
        ]);
    }
}
