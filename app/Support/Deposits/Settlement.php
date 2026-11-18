<?php

namespace App\Support\Deposits;

use App\Enums\BookingStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\DepositSettlement;
use App\Models\Setting;
use App\Support\BookingFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deposit settlement (4 Nov, section 4): after the return (and the hold period, when it is set),
 * the deposit pays what is still owed (return charges, fines, tolls, approved damage), the rest
 * goes back to the customer, and anything above the deposit is asked for. Never more than held
 * is refunded; the settlement is kept as a snapshot.
 */
class Settlement
{
    public static function holdDays(): ?int
    {
        $d = Setting::read('deposit_hold_days');

        return filled($d) && (int) $d > 0 ? (int) $d : null;
    }

    /** "Deposit refundable on": return date + hold days (when the setting is on). */
    public static function refundableOn(Booking $b): ?\Illuminate\Support\Carbon
    {
        $days = self::holdDays();

        return $days && $b->returned_at ? $b->returned_at->copy()->addDays($days)->startOfDay() : null;
    }

    public static function canSettle(Booking $b): bool
    {
        return $b->status === BookingStatus::Completed && $b->depositHeld() > 0;
    }

    /** What the settlement would do now. */
    public static function preview(Booking $b): array
    {
        $b->refresh();
        $held = $b->depositHeld();
        $owed = max(0, (float) $b->balance);
        $apply = round(min($held, $owed), 2);
        $lines = [['label' => 'Rental '.$b->reference, 'amount' => (float) $b->total]];
        foreach ((array) $b->charges as $key => $amount) {
            if ((float) $amount > 0) {
                $lines[] = ['label' => \App\Support\Options::chargeLabel($key).' (on return)', 'amount' => (float) $amount];
            }
        }
        foreach ($b->chargeLines()->whereNull('voided_at')->orderBy('id')->get() as $l) {
            $lines[] = ['label' => $l->label, 'amount' => (float) $l->amount];
        }
        $lines[] = ['label' => 'Paid so far', 'amount' => -(float) $b->paid_amount];

        return [
            'held' => $held,
            'owed' => round($owed, 2),
            'apply' => $apply,
            'refund' => round($held - $apply, 2),
            'to_collect' => round($owed - $apply, 2),
            'lines' => $lines,
            'refundable_on' => $b->deposit_refundable_on,
            'early' => $b->deposit_refundable_on && $b->deposit_refundable_on->isFuture(),
        ];
    }

    /**
     * @param  array{refund_method?: ?string, link?: bool, note?: ?string, preauth_release_ref?: ?string}  $data
     */
    public static function settle(Booking $b, array $data = []): DepositSettlement
    {
        if (! self::canSettle($b)) {
            throw ValidationException::withMessages(['deposit' => 'There is no deposit held on this completed booking.']);
        }
        if (\App\Models\DamageReport::query()->where('booking_id', $b->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['deposit' => 'A damage report on this booking is still to be reviewed (Damage reports).']);
        }

        $settlement = DB::transaction(function () use ($b, $data) {
            $b = Booking::query()->lockForUpdate()->findOrFail($b->id);
            $p = self::preview($b);
            if ($p['apply'] > 0) {
                $b->recordPayment($p['apply'], 'other', null, 'Taken from security deposit · '.$b->reference);
                BookingFlow::depositOut($b, $p['apply'], 'other', 'Deposit applied to balance · '.$b->reference);
            }
            if ($p['refund'] > 0) {
                BookingFlow::depositOut($b, $p['refund'], $data['refund_method'] ?? null, 'Deposit refund · '.$b->reference);
            }
            $b->refresh();
            $refundRow = $p['refund'] > 0 ? $b->transactions()->where('category', 'deposit_refund')->where('description', 'like', 'Deposit refund%')->latest('id')->first() : null;

            return DepositSettlement::create([
                'booking_id' => $b->id,
                'held' => $p['held'], 'owed' => $p['owed'], 'applied' => $p['apply'], 'refunded' => $p['refund'], 'to_collect' => $p['to_collect'],
                'lines' => $p['lines'],
                'refund_method' => $data['refund_method'] ?? null,
                'refund_transaction_id' => $refundRow?->id,
                'preauth_release_ref' => $data['preauth_release_ref'] ?? null,
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 500) : null,
                'created_by' => auth()->id(),
            ]);
        });

        if ($settlement->to_collect > 0 && ! empty($data['link'])) {
            $link = rescue(fn () => \App\Support\Payments\Payments::balanceLink($b->refresh()), null, false);
            if ($link) {
                $settlement->forceFill(['payment_request_id' => $link->id])->save();
            }
        }
        AuditLog::note($b, 'deposit', 'Deposit settled: held AED '.number_format((float) $settlement->held, 2).', used AED '.number_format((float) $settlement->applied, 2)
            .', refunded AED '.number_format((float) $settlement->refunded, 2).($settlement->to_collect > 0 ? ', still to collect AED '.number_format((float) $settlement->to_collect, 2) : ''));

        $c = $b->customer;
        \App\Support\Drivers\Notify::customer($c, 'Your deposit for '.$b->reference.' is settled',
            ($settlement->applied > 0 ? 'AED '.number_format((float) $settlement->applied, 2).' was used for what was still owed. ' : '')
            .($settlement->refunded > 0 ? 'AED '.number_format((float) $settlement->refunded, 2).' is refunded to you. ' : '')
            .($settlement->to_collect > 0 ? 'AED '.number_format((float) $settlement->to_collect, 2).' is still to pay.' : ''),
            url('/account/bookings/'.$b->id), 'deposit_refunded', $settlement, 'deposit_settled:'.$settlement->id, 'info');

        return $settlement;
    }

    /** Completed bookings with a deposit still held and the hold period over (or no hold set). */
    public static function dueQuery()
    {
        return Booking::query()->where('status', BookingStatus::Completed)
            ->where(fn ($q) => $q->whereNull('deposit_refundable_on')->orWhere('deposit_refundable_on', '<=', today()))
            ->whereRaw("(SELECT COALESCE(SUM(CASE WHEN type = 'income' AND category = 'deposit' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type = 'expense' AND category = 'deposit_refund' THEN amount ELSE 0 END), 0)
                FROM transactions WHERE transactions.booking_id = bookings.id AND transactions.status = 'paid' AND transactions.reversed_at IS NULL AND transactions.deleted_at IS NULL) > 0.004");
    }
}
