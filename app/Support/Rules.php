<?php

namespace App\Support;

use App\Enums\DriveMode;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Setting;
use App\Models\Yacht;
use Illuminate\Support\Carbon;

/**
 * Booking rules the business sets under Settings › Business settings.
 * Defaults keep the CRM working as before until someone changes them.
 */
class Rules
{
    public const LATE_MODES = [
        'manual' => 'Staff type the late charge themselves',
        'per_hour' => 'A fixed amount per late hour',
        'item_hourly' => "The car's / yacht's own hourly rate per late hour",
        'item_daily' => "One day at the car's daily rate for each started late day",
    ];

    /** Does a "Pending payment" booking keep the car / yacht for those dates? */
    public static function pendingPaymentHolds(): bool
    {
        return (bool) Setting::read('pending_payment_holds', true);
    }

    /** Gap kept free before and after each booking of the same car / yacht. */
    public static function bufferMinutes(): int
    {
        return max(0, (int) Setting::read('booking_buffer_minutes', 0));
    }

    public static function lateGraceMinutes(): int
    {
        return max(0, (int) Setting::read('late_grace_minutes', 30));
    }

    public static function tripMinutes(): int
    {
        return max(15, (int) Setting::read('trip_default_minutes', 120));
    }

    /** Default length of a new booking / quote when only the start is picked. */
    public static function defaultHours(?string $service): int
    {
        return $service === 'car'
            ? max(1, (int) Setting::read('car_default_hours', 24))
            : max(1, (int) Setting::read('yacht_default_hours', 4));
    }

    /** Minutes late after the grace period (0 when on time). */
    public static function minutesLate(Booking $booking, Carbon|string|null $returnedAt): int
    {
        $returnedAt = $returnedAt ? Carbon::parse($returnedAt) : now();
        $late = (int) $booking->end_at->diffInMinutes($returnedAt, false);

        return $late > self::lateGraceMinutes() ? $late : 0;
    }

    /** Suggested late charge from the rule in settings, or null when staff decide. */
    public static function lateCharge(Booking $booking, Carbon|string|null $returnedAt): ?float
    {
        $minutes = self::minutesLate($booking, $returnedAt);
        $mode = (string) Setting::read('late_charge_mode', 'manual');
        if (! $minutes || $mode === 'manual') {
            return null;
        }

        $hours = (int) ceil($minutes / 60);
        $item = $booking->bookable;
        $withDriver = $booking->drive_mode === DriveMode::WithDriver;

        $amount = match ($mode) {
            'per_hour' => $hours * (float) Setting::read('late_charge_amount', 0),
            'item_hourly' => $hours * (float) match (true) {
                $item instanceof Car => $withDriver ? ($item->chauffeur_hourly_price ?: 0) : ($item->hourly_price ?: 0),
                $item instanceof Yacht => $item->hourly_price ?: 0,
                default => 0,
            },
            'item_daily' => (int) ceil($minutes / 1440) * (float) match (true) {
                $item instanceof Car => $withDriver ? ($item->chauffeur_daily_price ?: $item->daily_price ?: 0) : ($item->daily_price ?: 0),
                $item instanceof Yacht => $item->full_day_price ?: 0,
                default => 0,
            },
            default => 0,
        };

        return $amount > 0 ? round($amount, 2) : null;
    }
}
