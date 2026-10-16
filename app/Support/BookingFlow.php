<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\PaymentStatus;
use App\Enums\Readiness;
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
            $charges = collect(Options::charges())
                ->map(fn ($meta, $key) => round((float) ($data['charges'][$key] ?? 0), 2))
                ->filter(fn ($v) => $v > 0);

            $booking->update([
                'status' => BookingStatus::Completed,
                'returned_at' => $data['returned_at'] ?? now(),
                'return_km' => $data['return_km'] ?? null,
                'return_fuel' => $data['return_fuel'] ?? null,
                'return_condition' => $data['return_condition'] ?? null,
                'inspection_result' => $data['after_return'] ?? null,
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
            // Booking status, money and the car's readiness are separate: the car is
            // only handed over again once it is marked ready.
            if ($item instanceof Car || $item instanceof Yacht) {
                $after = $data['after_return'] ?? 'ready';
                $note = trim(($booking->reference ? $booking->reference.': ' : '').($data['return_notes'] ?? ''), ': ');

                if ($after === 'maintenance') {
                    $item->update([
                        'status' => $item instanceof Car ? CarStatus::Maintenance : YachtStatus::Maintenance,
                        'readiness' => Readiness::Inspection,
                        'readiness_note' => mb_substr($note ?: 'Damaged on return', 0, 255),
                    ]);
                } else {
                    $readiness = Readiness::tryFrom($after) ?? Readiness::Inspection;
                    $item->update([
                        'readiness' => $readiness,
                        'readiness_note' => $readiness === Readiness::Ready ? null : mb_substr($note ?: 'After '.$booking->reference, 0, 255),
                    ]);
                    $item->refreshBookingStatus();
                }
            }

            $booking->refresh();
            $booking->advanceLead();

            return ['balance' => (float) $booking->balance, 'refunded' => $refunded, 'held' => $held];
        });
    }

    /** Cleaning / inspection done: the car or yacht can be handed over again. */
    public static function markReady(Car|Yacht $item, ?string $note = null): void
    {
        $fromMaintenance = in_array($item->status, [CarStatus::Maintenance, YachtStatus::Maintenance], true);
        $item->update(array_filter([
            'readiness' => Readiness::Ready,
            'readiness_note' => null,
            'status' => $fromMaintenance ? ($item instanceof Car ? CarStatus::Available : YachtStatus::Available) : null,
        ], fn ($v, $k) => $k === 'readiness_note' || $v !== null, ARRAY_FILTER_USE_BOTH));
        $item->refreshBookingStatus();
        if ($note) {
            \App\Models\AuditLog::note($item, 'ready', $note);
        }
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
            $booking->addMediaFromDisk($path, 'private')->toMediaCollection($collection);
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

    /** Hours late compared with the booked return time (grace period from Business settings). */
    public static function hoursLate(Booking $booking, Carbon|string|null $returnedAt): int
    {
        $late = Rules::minutesLate($booking, $returnedAt);

        return $late ? (int) ceil($late / 60) : 0;
    }
}
