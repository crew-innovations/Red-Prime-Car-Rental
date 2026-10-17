<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\CustomerType;
use App\Enums\DriveMode;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Trip;
use App\Models\User;
use App\Models\Yacht;
use App\Support\BookingFlow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** Sample bookings, a driver and a finished return. Runs only when there are no bookings yet. */
class DemoBookingsSeeder extends Seeder
{
    public function run(): void
    {
        if (Booking::withTrashed()->exists()) {
            return;
        }

        Role::firstOrCreate(['name' => 'Driver', 'guard_name' => 'web']);
        $driver = User::firstOrCreate(['email' => 'driver@redprime.test'], [
            'name' => 'Imran Driver', 'phone' => '+971500000111', 'password' => Str::random(32), 'is_active' => true,
        ]);
        $driver->syncRoles(['Driver']);

        Car::query()->update(['self_drive' => true]);
        Car::whereIn('brand', ['Rolls-Royce', 'Mercedes-Benz'])->get()->each(fn (Car $c) => $c->forceFill([
            'with_driver' => true,
            'chauffeur_daily_price' => $c->chauffeur_daily_price ?: round((float) $c->daily_price + 900),
            'chauffeur_hourly_price' => $c->chauffeur_hourly_price ?: 350,
            'driver_hours_per_day' => 10,
        ])->saveQuietly());

        $customer = fn (string $name, string $phone, string $email) => Customer::firstOrCreate(['phone' => $phone], [
            'name' => $name, 'whatsapp' => $phone, 'email' => $email, 'type' => CustomerType::Individual,
        ]);
        $ahmed = $customer('Ahmed Al Mansoori', '+971501112233', 'ahmed@example.com');
        $sofia = $customer('Sofia Lindqvist', '+46701234567', 'sofia@example.com');
        $karim = $customer('Karim Nasser', '+971503334455', 'karim@example.com');
        $mei = $customer('Mei Tanaka', '+819012345678', 'mei@example.com');

        $car = fn (string $brand) => Car::where('brand', $brand)->first();
        $at = fn (int $days, int $hour) => today()->addDays($days)->setTime($hour, 0);

        $make = function (array $data): ?Booking {
            if (empty($data['bookable_id'])) {
                return null;
            }
            $item = match ($data['service_type']) {
                ServiceType::Yacht => Yacht::find($data['bookable_id']),
                ServiceType::Trip => Trip::find($data['bookable_id']),
                default => Car::find($data['bookable_id']),
            };
            [$type, $qty, $rate, $amount] = Booking::quote($item, $data['start_at'], $data['end_at'], isset($data['drive_mode']) ? $data['drive_mode']->value : null, $data['guests'] ?? null);

            return Booking::create($data + [
                'rate_type' => $type, 'quantity' => $qty, 'rate' => $rate, 'base_amount' => $amount,
                'security_deposit' => (float) ($item->deposit ?? 0),
            ]);
        };

        // 1. Rolls-Royce out with Ahmed (self-drive), due back today.
        $rr = $car('Rolls-Royce');
        if ($rr) {
            $rr->forceFill(['status' => 'available'])->saveQuietly();
        }
        $b1 = $make([
            'customer_id' => $ahmed->id, 'lead_id' => Lead::where('phone', '+971501112233')->value('id'),
            'service_type' => ServiceType::Car, 'bookable_id' => $rr?->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => $at(-2, 10), 'end_at' => $at(0, 18), 'pickup_location' => 'Downtown Dubai',
            'status' => BookingStatus::Confirmed,
        ]);
        if ($b1) {
            $b1->recordPayment(round((float) $b1->total / 2), PaymentMethod::Card->value, null, 'Advance · '.$b1->reference);
            BookingFlow::deliver($b1->fresh(), [
                'delivered_at' => $at(-2, 10), 'delivery_km' => 12850, 'delivery_fuel' => 'full',
                'deposit_received' => (float) $b1->security_deposit, 'deposit_method' => PaymentMethod::Card->value,
            ]);
        }

        // 2. G63 with driver tomorrow, advance paid.
        $g63 = $car('Mercedes-Benz');
        if ($g63) {
            $g63->forceFill(['status' => 'available'])->saveQuietly();
        }
        $b2 = $make([
            'customer_id' => $sofia->id, 'service_type' => ServiceType::Car, 'bookable_id' => $g63?->id,
            'drive_mode' => DriveMode::WithDriver, 'driver_id' => $driver->id,
            'start_at' => $at(1, 9), 'end_at' => $at(3, 9), 'pickup_location' => 'Dubai International Airport (DXB)',
            'status' => BookingStatus::Confirmed,
        ]);
        $b2?->recordPayment(1500, PaymentMethod::Online->value, null, 'Advance · '.$b2->reference);

        // 3. Urus later this week, awaiting payment.
        $make([
            'customer_id' => $karim->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car('Lamborghini')?->id,
            'drive_mode' => DriveMode::SelfDrive, 'start_at' => $at(4, 12), 'end_at' => $at(7, 12),
            'pickup_location' => 'Palm Jumeirah', 'status' => BookingStatus::PendingPayment,
        ]);

        // 4. Yacht charter in two days (morning, so it does not clash with the evening trip on the same yacht).
        $yacht = Yacht::first();
        $b4 = $make([
            'customer_id' => $mei->id, 'service_type' => ServiceType::Yacht, 'bookable_id' => $yacht?->id,
            'start_at' => $at(2, 10), 'end_at' => $at(2, 14), 'guests' => 8, 'pickup_location' => 'Dubai Marina',
            'status' => BookingStatus::Confirmed,
        ]);
        $b4?->recordPayment(round((float) $b4->total * .5), PaymentMethod::BankTransfer->value, null, 'Deposit for charter · '.$b4->reference);

        // 5. Yacht trip seats.
        $trip = Trip::first();
        $date = $trip?->dates()->where('date', '>=', today())->orderBy('date')->first();
        if ($date) {
            $start = $date->date->copy()->setTimeFromTimeString($date->start_time ?? '17:30');
            $b5 = $make([
                'customer_id' => $sofia->id, 'service_type' => ServiceType::Trip, 'bookable_id' => $trip->id,
                'trip_date_id' => $date->id, 'guests' => 4, 'start_at' => $start, 'end_at' => $start->copy()->addMinutes($trip->duration_minutes ?: 120),
                'pickup_location' => $trip->meeting_point ?: $trip->departure_marina, 'status' => BookingStatus::Confirmed,
            ]);
            $b5?->recordPayment((float) $b5->total, PaymentMethod::Online->value);
        }

        // 6. Finished last week: Range Rover returned with extra charges, balance still to collect.
        $rangeRover = $car('Land Rover');
        $b6 = $make([
            'customer_id' => $karim->id, 'service_type' => ServiceType::Car, 'bookable_id' => $rangeRover?->id,
            'drive_mode' => DriveMode::SelfDrive, 'start_at' => $at(-9, 10), 'end_at' => $at(-6, 10),
            'pickup_location' => 'Office', 'status' => BookingStatus::Confirmed,
        ]);
        if ($b6) {
            $b6->recordPayment((float) $b6->total, PaymentMethod::Card->value);
            BookingFlow::deliver($b6->fresh(), ['delivered_at' => $at(-9, 10), 'delivery_km' => 30210, 'delivery_fuel' => 'full', 'deposit_received' => 1500, 'deposit_method' => PaymentMethod::Card->value]);
            BookingFlow::receive($b6->fresh(), [
                'returned_at' => $at(-6, 13), 'return_km' => 31180, 'return_fuel' => 'half', 'return_condition' => 'minor',
                'charges' => ['extra_km' => 480, 'fuel' => 180, 'salik' => 96, 'late' => 0],
                'deduct_from_deposit' => false, 'refund_deposit' => false,
                'return_notes' => 'Small scratch on rear bumper, customer informed.',
            ]);
        }
    }
}
