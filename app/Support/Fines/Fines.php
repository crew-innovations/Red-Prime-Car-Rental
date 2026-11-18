<?php

namespace App\Support\Fines;

use App\Enums\BookingStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TrafficFine;
use App\Support\Charges\ChargeLines;
use App\Support\Drivers\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Traffic fines (4 Nov, section 2): match to the booking that had the car, charge the customer
 * (pass-through line + admin fee), pay the authority (pass-through expense), or treat as a
 * company cost. Every step is logged; a re-match needs a reason.
 */
class Fines
{
    /** Finds the car and who had it. Never changes a fine that is already charged. */
    public static function match(TrafficFine $fine, ?string $reason = null): TrafficFine
    {
        if (in_array($fine->status, [FineStatus::Charged, FineStatus::Collected], true)) {
            throw ValidationException::withMessages(['fine' => 'This fine is already charged. Void the charge first.']);
        }
        $car = $fine->car ?? VehicleUse::car($fine->plate);
        if (! $car) {
            $fine->forceFill(['status' => FineStatus::New, 'match_note' => 'No car with plate '.($fine->plate ?: '—').'.'])->save();

            return $fine;
        }
        $use = VehicleUse::at($car, $fine->offence_at);
        $fine->forceFill([
            'car_id' => $car->id,
            'booking_id' => $use['booking']?->id,
            'customer_id' => $use['kind'] === 'customer' ? $use['booking']?->customer_id : null,
            'driver_id' => $use['driver_id'],
            'status' => $use['kind'] === 'customer' ? FineStatus::Matched : FineStatus::New,
            'match_note' => mb_substr($use['note'], 0, 255),
        ])->save();
        if ($reason) {
            AuditLog::note($fine, 'fine', 'Matched again: '.$reason.' → '.$use['note']);
        }

        return $fine;
    }

    /** Admin fee for a fine (Business settings; empty = none). */
    public static function adminFee(float $amount): float
    {
        $value = Setting::read('fine_admin_fee_amount');
        if ($value === null || $value === '' || (float) $value <= 0) {
            return 0.0;
        }
        $fee = Setting::read('fine_admin_fee_mode', 'fixed') === 'percent' ? $amount * (float) $value / 100 : (float) $value;
        $min = (float) Setting::read('fine_admin_fee_min', 0);

        return round(max($fee, $min), 2);
    }

    /**
     * Adds the fine (and the admin fee) to the customer's booking. With the deposit already given
     * back, an invoice for the new charges is issued; a payment link is made when a gateway is on.
     */
    public static function charge(TrafficFine $fine, ?float $adminFee = null): TrafficFine
    {
        $b = $fine->booking;
        if (! $b || ! $fine->customer_id || $fine->status !== FineStatus::Matched) {
            throw ValidationException::withMessages(['fine' => 'Match the fine to a customer\'s booking first.']);
        }
        if (in_array($b->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['fine' => 'Booking '.$b->reference.' is '.$b->status->getLabel().'.']);
        }
        $fee = $adminFee ?? self::adminFee((float) $fine->amount);

        DB::transaction(function () use ($fine, $b, $fee) {
            $fine = TrafficFine::query()->lockForUpdate()->findOrFail($fine->id);
            if ($fine->status !== FineStatus::Matched) {
                throw ValidationException::withMessages(['fine' => 'This fine was already charged.']);
            }
            $what = 'Traffic fine '.$fine->ticket_no.' · '.$fine->offence_at->format('d M Y H:i').($fine->offence ? ' · '.$fine->offence : '');
            ChargeLines::add($b, 'fine', $what, (float) $fine->amount, $fine);
            if ($fee > 0) {
                ChargeLines::add($b, 'admin_fee', 'Admin fee · fine '.$fine->ticket_no, $fee, $fine);
            }
            $fine->forceFill(['status' => FineStatus::Charged, 'charged_at' => now(), 'admin_fee' => $fee > 0 ? $fee : null])->save();
            AuditLog::note($b, 'fine', 'Fine '.$fine->title().' charged: AED '.number_format((float) $fine->amount, 2).($fee > 0 ? ' + admin fee AED '.number_format($fee, 2) : ''));
        });
        $fine->refresh();
        $b->refresh();

        // Deposit already given back and the booking invoiced: a separate invoice for the new charges.
        if ($b->status === BookingStatus::Completed && $b->depositHeld() <= 0) {
            rescue(fn () => \App\Models\Invoice::issueCharges($b), report: false);
        }

        $total = (float) $fine->amount + (float) $fine->admin_fee;
        Notify::customer($b->customer, 'Traffic fine on your rental '.$b->reference,
            ($fine->authority.' fine '.$fine->ticket_no.' on '.$fine->offence_at->format('d M Y H:i').($fine->location ? ' at '.$fine->location : '').': AED '.number_format($total, 2)
                .((float) $fine->admin_fee > 0 ? ' (incl. admin fee AED '.number_format((float) $fine->admin_fee, 2).')' : '').'. It is added to your booking.'),
            url('/account/bookings/'.$b->id), 'fine_charged', $fine, 'fine_charged:'.$fine->id, 'warning');

        return $fine;
    }

    /** Takes the fine (and fee) off the booking: waived, disputed or a mistake. */
    public static function uncharge(TrafficFine $fine, string $reason, FineStatus $to): TrafficFine
    {
        foreach ($fine->charges()->whereNull('voided_at')->get() as $line) {
            ChargeLines::void($line, $reason);
        }
        $fine->forceFill(['status' => $to, 'dispute_note' => mb_substr($reason, 0, 500)])->save();
        AuditLog::note($fine, 'fine', $to->getLabel().': '.$reason);

        return $fine;
    }

    /** Status change without money (disputed, waived, company cost, supplier). */
    public static function setStatus(TrafficFine $fine, FineStatus $to, string $note): TrafficFine
    {
        if (in_array($fine->status, [FineStatus::Charged, FineStatus::Collected], true)) {
            return self::uncharge($fine, $note, $to);
        }
        $fine->forceFill(['status' => $to, 'dispute_note' => mb_substr($note, 0, 500)])->save();
        AuditLog::note($fine, 'fine', $to->getLabel().': '.$note);

        return $fine;
    }

    /**
     * We paid the authority. For a fine passed on to the customer it is a pass-through expense
     * (not a cost); otherwise it is a normal "Salik & fines" cost.
     */
    public static function payAuthority(TrafficFine $fine, string $method, ?string $reference = null, $date = null): Transaction
    {
        if ($fine->paid_at) {
            throw ValidationException::withMessages(['fine' => 'Already paid on '.$fine->paid_at->format('d M Y').'.']);
        }
        $passThrough = in_array($fine->status, [FineStatus::Charged, FineStatus::Collected], true);

        return DB::transaction(function () use ($fine, $method, $reference, $date, $passThrough) {
            $t = Transaction::create([
                'type' => TransactionType::Expense,
                'category' => $passThrough ? 'fines_paid' : 'salik_fines',
                'pass_through' => $passThrough,
                'source_type' => $fine->getMorphClass(),
                'source_id' => $fine->id,
                'booking_id' => $passThrough ? $fine->booking_id : null,
                'related_type' => $fine->car_id ? 'car' : null,
                'related_id' => $fine->car_id,
                'date' => $date ?? today(),
                'amount' => $fine->amount,
                'status' => PaymentStatus::Paid,
                'method' => $method,
                'reference' => $reference,
                'description' => 'Fine '.$fine->title().' paid to the authority',
            ]);
            $fine->forceFill(['paid_at' => now(), 'paid_reference' => $reference])->save();
            AuditLog::note($fine, 'fine', 'Paid to '.$fine->authority.($reference ? ' (ref '.$reference.')' : ''));

            return $t;
        });
    }

    /** A booking's balance reached 0: its charged fines are collected (called from syncBalance). */
    public static function markCollected(Booking $b): void
    {
        if ((float) $b->balance > 0) {
            return;
        }
        TrafficFine::query()->where('booking_id', $b->id)->where('status', FineStatus::Charged->value)->update(['status' => FineStatus::Collected->value, 'updated_at' => now()]);
    }
}
