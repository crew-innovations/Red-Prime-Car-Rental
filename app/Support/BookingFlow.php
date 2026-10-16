<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Enums\YachtStatus;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Yacht;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Handover and return of cars / yachts (Schedule A10), with the money side:
 * security deposit, payments, return charges and deposit refunds.
 */
class BookingFlow
{
    /** Hand the car over / start the charter. */
    public static function deliver(Booking $booking, array $data): void
    {
        DB::transaction(function () use ($booking, $data) {
            $booking->update([
                'status' => BookingStatus::InProgress,
                'delivered_at' => $data['delivered_at'] ?? now(),
                'delivery_km' => $data['delivery_km'] ?? null,
                'delivery_fuel' => $data['delivery_fuel'] ?? null,
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'delivered_by' => auth()->id(),
                'driver_id' => $data['driver_id'] ?? $booking->driver_id,
            ]);

            if (($deposit = (float) ($data['deposit_received'] ?? 0)) > 0) {
                $booking->recordPayment($deposit, $data['deposit_method'] ?? null, 'deposit', 'Security deposit · '.$booking->reference);
            }

            if (($paid = (float) ($data['payment_amount'] ?? 0)) > 0) {
                $booking->recordPayment($paid, $data['payment_method'] ?? null);
            }

            self::attachPhotos($booking, $data['photos'] ?? [], 'delivery');

            if ($booking->bookable instanceof Car && ! empty($data['delivery_km']) && (int) $data['delivery_km'] > (int) $booking->bookable->mileage) {
                $booking->bookable->forceFill(['mileage' => (int) $data['delivery_km']])->saveQuietly();
            }
        });
    }

    /**
     * Receive the car back / close the charter. Adds return charges, takes
     * payment, uses or refunds the deposit, then frees the car for new bookings.
     *
     * @return array{balance: float, refunded: float, held: float}
     */
    public static function receive(Booking $booking, array $data): array
    {
        return DB::transaction(function () use ($booking, $data) {
            $charges = collect(Booking::CHARGES)
                ->map(fn ($meta, $key) => round((float) ($data['charges'][$key] ?? 0), 2))
                ->filter(fn ($v) => $v > 0);

            $booking->update([
                'status' => BookingStatus::Completed,
                'returned_at' => $data['returned_at'] ?? now(),
                'return_km' => $data['return_km'] ?? null,
                'return_fuel' => $data['return_fuel'] ?? null,
                'return_condition' => $data['return_condition'] ?? null,
                'return_notes' => $data['return_notes'] ?? null,
                'received_by' => auth()->id(),
                'charges' => $charges->all() ?: null,
                'extra_charges' => $charges->sum(),
            ]);
            $booking->refresh();

            if (($paid = (float) ($data['payment_amount'] ?? 0)) > 0) {
                $booking->recordPayment($paid, $data['payment_method'] ?? null, $charges->isNotEmpty() ? 'extras' : null);
                $booking->refresh();
            }

            // Use the deposit for whatever is still owed.
            $held = $booking->depositHeld();
            if (! empty($data['deduct_from_deposit']) && $held > 0 && (float) $booking->balance > 0) {
                $use = min($held, (float) $booking->balance);
                $booking->recordPayment($use, 'other', $charges->isNotEmpty() ? 'extras' : null, 'Taken from security deposit · '.$booking->reference);
                self::depositOut($booking, $use, 'other', 'Deposit applied to balance · '.$booking->reference);
                $booking->refresh();
                $held = $booking->depositHeld();
            }

            $refunded = 0.0;
            if (! empty($data['refund_deposit']) && $held > 0) {
                self::depositOut($booking, $held, $data['refund_method'] ?? null, 'Deposit refund · '.$booking->reference);
                $refunded = $held;
                $held = 0.0;
            }

            self::attachPhotos($booking, $data['photos'] ?? [], 'return');

            $item = $booking->bookable;
            if ($item instanceof Car && ! empty($data['return_km']) && (int) $data['return_km'] > (int) $item->mileage) {
                $item->forceFill(['mileage' => (int) $data['return_km']])->saveQuietly();
            }
            if (! empty($data['send_to_maintenance']) && ($item instanceof Car || $item instanceof Yacht)) {
                $item->forceFill(['status' => $item instanceof Car ? CarStatus::Maintenance : YachtStatus::Maintenance])->saveQuietly();
            } else {
                $item?->refreshBookingStatus();
            }

            $booking->refresh();
            $booking->advanceLead();

            return ['balance' => (float) $booking->balance, 'refunded' => $refunded, 'held' => $held];
        });
    }

    public static function refundDeposit(Booking $booking, float $amount, ?string $method): void
    {
        self::depositOut($booking, min($amount, $booking->depositHeld()), $method, 'Deposit refund · '.$booking->reference);
    }

    protected static function depositOut(Booking $booking, float $amount, ?string $method, string $description): void
    {
        if ($amount <= 0) {
            return;
        }

        $booking->transactions()->create([
            'type' => TransactionType::Expense,
            'category' => 'deposit_refund',
            'date' => today(),
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'method' => $method,
            'description' => $description,
        ]);
    }

    protected static function attachPhotos(Booking $booking, array $paths, string $collection): void
    {
        foreach (array_filter($paths) as $path) {
            $booking->addMediaFromDisk($path, 'uploads')->toMediaCollection($collection);
        }
    }

    /** Suggested extra-km charge for a car return. */
    public static function extraKmCharge(Booking $booking, ?int $returnKm): float
    {
        $car = $booking->bookable;
        if (! $car instanceof Car || ! $returnKm || ! $booking->delivery_km || ! $car->daily_km_limit || ! $car->extra_km_charge) {
            return 0;
        }

        $days = max(1, (int) ceil($booking->start_at->diffInHours($booking->end_at) / 24));
        $over = ($returnKm - $booking->delivery_km) - $days * $car->daily_km_limit;

        return $over > 0 ? round($over * (float) $car->extra_km_charge, 2) : 0;
    }

    /** Hours late compared with the booked return time (30 minutes grace). */
    public static function hoursLate(Booking $booking, Carbon|string|null $returnedAt): int
    {
        $returnedAt = $returnedAt ? Carbon::parse($returnedAt) : now();
        $late = $booking->end_at->diffInMinutes($returnedAt, false);

        return $late > 30 ? (int) ceil($late / 60) : 0;
    }
}
