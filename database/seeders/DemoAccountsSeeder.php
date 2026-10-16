<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Car;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\Yacht;
use Illuminate\Database\Seeder;

/**
 * Six months of sample income and expenses for demos. Only runs when there are
 * no transactions yet, so it never mixes with real figures.
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (Transaction::withTrashed()->exists()) {
            return;
        }

        mt_srand(2026);

        $cars = Car::all();
        $yachts = Yacht::all();
        $trip = Trip::first();
        $customers = Customer::pluck('id')->all();
        $methods = [PaymentMethod::Card, PaymentMethod::BankTransfer, PaymentMethod::Cash, PaymentMethod::Online];

        $add = function (array $data) {
            Transaction::create($data + ['status' => PaymentStatus::Paid]);
        };

        for ($m = 5; $m >= 0; $m--) {
            $month = now()->subMonthsNoOverflow($m)->startOfMonth();
            $lastDay = $m === 0 ? now()->day : $month->daysInMonth;
            $day = fn () => $month->copy()->addDays(mt_rand(0, $lastDay - 1));
            $growth = 1 + (5 - $m) * 0.08;

            foreach ($cars as $car) {
                foreach (range(1, mt_rand(2, 4)) as $i) {
                    $days = mt_rand(1, 4);
                    $add([
                        'type' => TransactionType::Income, 'category' => 'car_rental', 'date' => $day(),
                        'amount' => round(($car->daily_price ?: 1500) * $days * $growth),
                        'method' => $methods[array_rand($methods)], 'related_type' => 'car', 'related_id' => $car->id,
                        'customer_id' => $customers ? $customers[array_rand($customers)] : null,
                        'description' => "{$car->name}, {$days} day".($days > 1 ? 's' : ''),
                    ]);
                }
                $add(['type' => TransactionType::Expense, 'category' => 'fuel', 'date' => $day(), 'amount' => mt_rand(250, 700), 'method' => PaymentMethod::Card, 'related_type' => 'car', 'related_id' => $car->id, 'description' => 'Fuel']);
                $add(['type' => TransactionType::Expense, 'category' => 'salik_fines', 'date' => $day(), 'amount' => mt_rand(40, 260), 'method' => PaymentMethod::Online, 'related_type' => 'car', 'related_id' => $car->id, 'description' => 'Salik']);
                if (mt_rand(0, 2) === 0) {
                    $add(['type' => TransactionType::Expense, 'category' => 'maintenance', 'date' => $day(), 'amount' => mt_rand(600, 3500), 'method' => PaymentMethod::BankTransfer, 'related_type' => 'car', 'related_id' => $car->id, 'description' => 'Service / repairs']);
                }
            }

            foreach ($yachts as $yacht) {
                foreach (range(1, mt_rand(2, 5)) as $i) {
                    $hours = mt_rand(2, 6);
                    $add([
                        'type' => TransactionType::Income, 'category' => 'yacht_charter', 'date' => $day(),
                        'amount' => round(($yacht->hourly_price ?: 900) * $hours * $growth),
                        'method' => $methods[array_rand($methods)], 'related_type' => 'yacht', 'related_id' => $yacht->id,
                        'customer_id' => $customers ? $customers[array_rand($customers)] : null,
                        'description' => "{$yacht->name}, {$hours} hours",
                    ]);
                }
                $add(['type' => TransactionType::Expense, 'category' => 'marina', 'date' => $month->copy()->addDays(2), 'amount' => 3500, 'method' => PaymentMethod::BankTransfer, 'related_type' => 'yacht', 'related_id' => $yacht->id, 'description' => 'Monthly berth']);
                $add(['type' => TransactionType::Expense, 'category' => 'fuel', 'date' => $day(), 'amount' => mt_rand(1500, 4000), 'method' => PaymentMethod::Card, 'related_type' => 'yacht', 'related_id' => $yacht->id, 'description' => 'Marine fuel']);
            }

            if ($trip) {
                foreach (range(1, mt_rand(4, 8)) as $i) {
                    $guests = mt_rand(2, 6);
                    $add([
                        'type' => TransactionType::Income, 'category' => 'yacht_trip', 'date' => $day(),
                        'amount' => round(($trip->price ?: 299) * $guests), 'method' => PaymentMethod::Online,
                        'related_type' => 'trip', 'related_id' => $trip->id, 'description' => "{$guests} guests",
                    ]);
                }
            }

            foreach ([['salaries', 18000, 'Staff salaries'], ['rent', 7500, 'Office rent'], ['marketing', mt_rand(2500, 6000), 'Instagram & Google ads']] as [$cat, $amount, $desc]) {
                $add(['type' => TransactionType::Expense, 'category' => $cat, 'date' => $month->copy()->addDays(min(24, $lastDay - 1)), 'amount' => $amount, 'method' => PaymentMethod::BankTransfer, 'description' => $desc]);
            }
        }

        // A few open items to collect this month.
        $car = $cars->first();
        $add(['type' => TransactionType::Income, 'category' => 'car_rental', 'date' => today(), 'amount' => 10500, 'status' => PaymentStatus::PartiallyPaid, 'amount_paid' => 3500, 'due_date' => today()->addDays(3), 'method' => PaymentMethod::Card, 'related_type' => 'car', 'related_id' => $car?->id, 'customer_id' => $customers[0] ?? null, 'description' => 'Weekly rental, balance on return']);
        $add(['type' => TransactionType::Income, 'category' => 'yacht_charter', 'date' => today()->subDays(9), 'amount' => 4400, 'status' => PaymentStatus::Overdue, 'due_date' => today()->subDays(2), 'related_type' => 'yacht', 'related_id' => $yachts->first()?->id, 'customer_id' => $customers[1] ?? $customers[0] ?? null, 'description' => 'Half-day charter, invoice sent']);
        $add(['type' => TransactionType::Income, 'category' => 'car_rental', 'date' => today(), 'amount' => 2750, 'status' => PaymentStatus::Pending, 'due_date' => today()->addDays(1), 'related_type' => 'car', 'related_id' => $cars->skip(3)->first()?->id, 'description' => 'G63 reservation']);
    }
}
