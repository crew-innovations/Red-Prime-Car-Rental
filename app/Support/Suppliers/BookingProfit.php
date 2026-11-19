<?php

namespace App\Support\Suppliers;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Transaction;
use App\Support\Accounts\ProfitReport;

/**
 * Booking profit (29 Oct, 13), with the existing accounting rules:
 *  revenue = price + return charges, without VAT (money kept on a cancellation)
 *  estimated profit = revenue − (supplier cost on the booking + other direct costs entered)
 *  actual profit    = revenue − costs actually posted and approved (only once the job is done)
 *  margin           = profit ÷ revenue × 100, when revenue is above zero
 * The supplier cost is counted once: the posted bill replaces the estimate, never both.
 */
class BookingProfit
{
    public static function for(Booking $b): array
    {
        $revenue = ProfitReport::earnedFor($b);
        $costs = Transaction::query()->expense()->where('booking_id', $b->id)->whereNull('reversed_at')->where('status', '<>', PaymentStatus::Refunded->value)
            ->whereNotIn('category', Transaction::NON_COST_CATEGORIES)->where('pass_through', false)->get();
        $counting = $costs->filter(fn (Transaction $t) => $t->counts());
        $supplierRows = $counting->filter(fn (Transaction $t) => $t->supplier_id);
        // 19 Nov (suggestion 8): a marketplace's commission is a cost of the booking.
        $commission = round((float) $b->marketplace_commission, 2);
        $other = round($counting->reject(fn (Transaction $t) => $t->supplier_id)->sum('amount') + $commission, 2);
        $waiting = round($costs->where('approval_status', 'pending')->sum('amount'), 2);

        $posted = SupplierCost::billPosted($b);
        $supplierEstimate = $b->supplier_id ? ($posted ? round($supplierRows->sum('amount'), 2) : ($b->supplier_cost !== null ? (float) $b->supplier_cost : null)) : 0.0;
        $estimatedCost = $supplierEstimate === null ? null : round($supplierEstimate + $other, 2);
        $estimated = $estimatedCost === null ? null : round($revenue - $estimatedCost, 2);

        $done = in_array($b->status, [BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow], true);
        $actualReady = $done && (! $b->supplier_id || ($posted && $supplierRows->isNotEmpty()) || ($b->supplier_cost !== null && (float) $b->supplier_cost === 0.0)) && $waiting <= 0;
        $actualCost = round($counting->sum('amount') + $commission, 2);
        $actual = $actualReady ? round($revenue - $actualCost, 2) : null;

        $margin = fn (?float $p) => $p !== null && $revenue > 0 ? round($p / $revenue * 100, 1) : null;

        return [
            'revenue' => $revenue,
            'supplier' => $supplierEstimate,
            'supplier_posted' => $posted,
            'other' => $other,
            'estimated_cost' => $estimatedCost,
            'estimated' => $estimated,
            'estimated_margin' => $margin($estimated),
            'actual_cost' => $actualReady ? $actualCost : null,
            'actual' => $actual,
            'actual_margin' => $margin($actual),
            'waiting_approval' => $waiting,
            'why_no_actual' => $actualReady ? null : match (true) {
                ! $done => 'Final once the job is completed.',
                $waiting > 0 => 'A cost is waiting for approval.',
                default => 'The supplier bill is not posted yet.',
            },
            'supplier_paid' => $b->supplier_bill_id && ($bill = Transaction::find($b->supplier_bill_id)) ? ($bill->status?->getLabel() ?? '') : null,
        ];
    }
}
