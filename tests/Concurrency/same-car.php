<?php
// Two staff save the same car for the same dates at the same moment.
require ''.__DIR__.'/../../vendor/autoload.php';
$app = require ''.__DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$who, $carId, $customerId] = [$argv[1], (int) $argv[2], (int) $argv[3]];
auth()->login(App\Models\User::role('Super Admin')->first());
if ($who === 'B') usleep(800000);
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($who, $carId, $customerId) {
        App\Models\Booking::count(); App\Models\Customer::find($customerId); // other reads first, like a real form save
        App\Models\Booking::create(['status' => 'confirmed', 'customer_id' => $customerId, 'service_type' => 'car', 'bookable_id' => $carId,
            'drive_mode' => 'self_drive', 'start_at' => '2027-03-01 10:00', 'end_at' => '2027-03-03 10:00', 'base_amount' => 2000]);
        if ($who === 'A') sleep(3); // still saving when B starts
    });
    echo "$who SAVED\n";
} catch (Illuminate\Validation\ValidationException $e) {
    echo "$who REFUSED: ".collect($e->errors())->flatten()->first()."\n";
}
