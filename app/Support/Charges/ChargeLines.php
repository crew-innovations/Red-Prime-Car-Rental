<?php

namespace App\Support\Charges;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingCharge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charge lines on a booking (4 Nov, C.1). The only way lines are added or voided, so the booking's
 * balance is always worked out again by Booking::syncBalance() (still the one calculation).
 */
class ChargeLines
{
    public static function add(Booking $b, string $type, string $label, float $amount, ?Model $source = null, float $qty = 1, ?float $unit = null, ?string $nature = null): BookingCharge
    {
        if (! array_key_exists($type, BookingCharge::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Unknown charge type.']);
        }
        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be above zero.']);
        }

        return DB::transaction(function () use ($b, $type, $label, $amount, $source, $qty, $unit, $nature) {
            $line = BookingCharge::create([
                'booking_id' => $b->id,
                'type' => $type,
                'label' => mb_substr($label, 0, 255),
                'quantity' => $qty,
                'unit_amount' => round($unit ?? ($qty > 0 ? $amount / $qty : $amount), 2),
                'amount' => round($amount, 2),
                'nature' => $nature ?? (in_array($type, BookingCharge::PASS_THROUGH_TYPES, true) ? 'pass_through' : 'revenue'),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_by' => auth()->id(),
            ]);
            $b->refresh()->syncBalance();

            return $line;
        });
    }

    /** Change a rolled-up line (tolls) while it is not invoiced. */
    public static function update(BookingCharge $line, float $amount, float $qty, string $label): BookingCharge
    {
        if ($line->invoice_id || $line->voided_at) {
            throw ValidationException::withMessages(['line' => 'This line is invoiced or voided; add a new one.']);
        }
        $line->update(['amount' => round($amount, 2), 'quantity' => $qty, 'unit_amount' => $qty > 0 ? round($amount / $qty, 2) : round($amount, 2), 'label' => mb_substr($label, 0, 255)]);
        $line->booking->refresh()->syncBalance();

        return $line;
    }

    public static function void(BookingCharge $line, string $reason): void
    {
        if ($line->voided_at) {
            return;
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }
        if ($line->invoice_id && $line->invoice?->status === 'issued') {
            throw ValidationException::withMessages(['line' => 'This line is on invoice '.$line->invoice->number.'. Issue a credit note instead.']);
        }
        $line->update(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 255)]);
        AuditLog::note($line->booking, 'charge', 'Charge voided: '.$line->label.' (AED '.number_format((float) $line->amount, 2).'): '.$reason);
        $line->booking->refresh()->syncBalance();
    }
}
