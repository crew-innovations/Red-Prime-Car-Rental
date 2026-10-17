<?php
require ''.__DIR__.'/../../vendor/autoload.php';
$app = require ''.__DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$who, $tripId, $dateId, $customerId] = [$argv[1], (int) $argv[2], (int) $argv[3], (int) $argv[4]];
auth()->login(App\Models\User::role('Super Admin')->first());
if ($who === 'B') usleep(800000);
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($who, $tripId, $dateId, $customerId) {
        App\Models\Booking::count(); App\Models\TripDate::find($dateId); // other reads first
        App\Models\Booking::create(['status' => 'confirmed', 'customer_id' => $customerId, 'service_type' => 'trip', 'bookable_id' => $tripId, 'trip_date_id' => $dateId,
            'guests' => 6, 'start_at' => '2027-03-05 17:30', 'end_at' => '2027-03-05 19:30', 'base_amount' => 1800]);
        if ($who === 'A') sleep(3);
    });
    echo "$who SAVED 6 seats\n";
} catch (Illuminate\Validation\ValidationException $e) {
    echo "$who REFUSED: ".collect($e->errors())->flatten()->first()."\n";
}
