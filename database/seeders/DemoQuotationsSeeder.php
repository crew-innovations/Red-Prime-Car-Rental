<?php

namespace Database\Seeders;

use App\Enums\DriveMode;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Yacht;
use Illuminate\Database\Seeder;

/** Sample quotations and a usual driver. Runs only when there are no quotations yet. */
class DemoQuotationsSeeder extends Seeder
{
    public function run(): void
    {
        if (Quotation::withTrashed()->exists()) {
            return;
        }

        // The demo driver usually drives the chauffeur cars.
        if ($driver = User::where('email', 'driver@redprime.test')->first()) {
            Car::where('with_driver', true)->get()->each(fn (Car $c) => $c->drivers()->syncWithoutDetaching([$driver->id]));
        }

        $at = fn (int $days, int $hour) => today()->addDays($days)->setTime($hour, 0);
        $make = function (array $data): ?Quotation {
            $item = $data['service_type'] === ServiceType::Yacht ? Yacht::find($data['bookable_id']) : Car::find($data['bookable_id']);
            if (! $item) {
                return null;
            }
            [$type, $qty, $rate, $amount] = Booking::quote($item, $data['start_at'], $data['end_at'], $data['drive_mode']?->value ?? null, $data['guests'] ?? null) ?? [null, null, null, 0];

            return Quotation::create($data + [
                'rate_type' => $type, 'quantity' => $qty, 'rate' => $rate, 'base_amount' => $amount,
                'security_deposit' => (float) ($item->deposit ?? 0),
                'valid_until' => today()->addDays(3),
            ]);
        };

        // Sent to a lead, waiting for an answer.
        $lead = Lead::whereNull('customer_id')->where('service_type', ServiceType::Car)->first();
        $car = Car::where('with_driver', true)->first() ?? Car::first();
        $q1 = $make([
            'status' => QuotationStatus::Draft, 'lead_id' => $lead?->id, 'customer_id' => $lead ? null : Customer::value('id'),
            'service_type' => ServiceType::Car, 'bookable_id' => $car?->id,
            'drive_mode' => $car?->with_driver ? DriveMode::WithDriver : DriveMode::SelfDrive,
            'start_at' => $at(10, 10), 'end_at' => $at(12, 10), 'pickup_location' => 'Hotel delivery, Downtown Dubai',
            'extras' => [['label' => 'Delivery & pick-up', 'amount' => 200]],
            'customer_notes' => 'Includes 250 km per day and full insurance.',
        ]);
        $q1?->update(['status' => QuotationStatus::Sent, 'sent_at' => now()->subHours(3)]);

        // Draft yacht charter for an existing customer.
        $make([
            'status' => QuotationStatus::Draft, 'customer_id' => Customer::orderByDesc('id')->value('id'),
            'service_type' => ServiceType::Yacht, 'bookable_id' => Yacht::value('id'),
            'start_at' => $at(14, 16), 'end_at' => $at(14, 20), 'guests' => 10, 'pickup_location' => 'Dubai Marina',
            'discount' => 300, 'adjustment_reason' => 'Returning customer',
        ]);
    }
}
