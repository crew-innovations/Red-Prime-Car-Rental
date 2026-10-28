<?php

namespace App\Support\Accounts;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Why a booking's balance is what it is (28 Oct, Priority 8.4): price changes from
 * the history log, return charges, payments, refunds, reversals and credit notes,
 * oldest first. It only explains; Booking::syncBalance() stays the one calculation.
 */
class BalanceHistory
{
    /** @return list<array{at: Carbon, what: string, who: ?string, due: ?float, paid: ?float, note: ?string}> */
    public static function for(Booking $b): array
    {
        $events = [];
        $logs = AuditLog::query()->with('user')
            ->where('auditable_type', $b->getMorphClass())->where('auditable_id', $b->getKey())
            ->where('event', 'updated')->oldest('created_at')->oldest('id')->get();

        $priceLogs = $logs->filter(fn (AuditLog $l) => array_key_exists('total', (array) $l->new_values) || array_key_exists('extra_charges', (array) $l->new_values));
        $firstPrice = $priceLogs->first(fn (AuditLog $l) => array_key_exists('total', (array) $l->old_values));
        $startTotal = (float) ($firstPrice ? $firstPrice->old_values['total'] : $b->total);

        $events[] = ['at' => $b->created_at ?? now(), 'what' => 'Booking created', 'who' => null, 'due' => round($startTotal, 2), 'paid' => null, 'note' => null];

        foreach ($priceLogs as $log) {
            $old = (array) $log->old_values;
            $new = (array) $log->new_values;
            foreach (['total' => 'Price changed', 'extra_charges' => 'Return charges'] as $field => $label) {
                if (! array_key_exists($field, $new)) {
                    continue;
                }
                $delta = round((float) $new[$field] - (float) ($old[$field] ?? 0), 2);
                if ($delta == 0.0) {
                    continue;
                }
                $note = $field === 'total' ? ($new['adjustment_reason'] ?? null) : null;
                $events[] = ['at' => $log->created_at, 'what' => $label.' to AED '.number_format((float) $new[$field], 2), 'who' => $log->user?->name, 'due' => $delta, 'paid' => null, 'note' => $note];
            }
        }

        $rows = $b->transactions()->where('is_balance', false)->orderBy('date')->orderBy('id')->get();
        foreach ($rows as $t) {
            /** @var Transaction $t */
            $deposit = in_array($t->category, ['deposit', 'deposit_refund'], true);
            $income = $t->type === TransactionType::Income;
            $amount = $t->status === PaymentStatus::PartiallyPaid ? (float) $t->amount_paid : (float) $t->amount;
            $label = match (true) {
                $deposit && $income => 'Security deposit received (not part of the balance)',
                $deposit => 'Security deposit returned (not part of the balance)',
                $income && $t->status === PaymentStatus::Refunded => 'Payment refunded',
                $income => 'Payment received'.($t->method ? ' · '.$t->method->getLabel() : ''),
                default => 'Cost · '.(Transaction::categories()[$t->category] ?? $t->category),
            };
            $counts = $income && ! $deposit && $t->counts() && in_array($t->status, [PaymentStatus::Paid, PaymentStatus::PartiallyPaid], true);
            $events[] = ['at' => $t->created_at ?? Carbon::parse($t->date), 'what' => $label, 'who' => null,
                'due' => null, 'paid' => $income && ! $deposit ? ($counts ? round($amount, 2) : 0.0) : null,
                'note' => match (true) {
                    (bool) $t->reversed_at => 'Reversed: '.$t->reversal_reason,
                    $t->is_estimate => 'Estimate, not counted',
                    $t->approval_status === 'pending' => 'Waiting for approval',
                    default => $deposit || ! $income ? 'AED '.number_format($amount, 2) : null,
                }];
        }

        foreach (Invoice::query()->where('booking_id', $b->id)->oldest('id')->get() as $inv) {
            $events[] = ['at' => $inv->created_at, 'what' => ($inv->type === 'credit_note' ? 'Credit note ' : 'Invoice ').$inv->number.($inv->status === 'void' ? ' (void)' : ''),
                'who' => null, 'due' => null, 'paid' => null, 'note' => 'AED '.number_format((float) $inv->total, 2).($inv->type === 'credit_note' ? ' · lower the price to match if not done yet' : '')];
        }

        usort($events, fn ($a, $c) => $a['at'] <=> $c['at']);

        if (! in_array($b->status, \App\Enums\BookingStatus::billable(), true)) {
            $events[] = ['at' => $b->updated_at ?? now(), 'what' => 'Booking is '.$b->status->getLabel().': nothing more to collect', 'who' => null, 'due' => null, 'paid' => null, 'note' => 'Payments already received stay recorded.'];
        }

        return $events;
    }

    public static function html(Booking $b): string
    {
        $aed = fn (?float $v) => $v === null ? '' : ($v < 0 ? '−' : '').'AED '.number_format(abs($v), 2);
        $out = '<table class="rp-bal-hist"><thead><tr><th>When</th><th>What</th><th class="r">Due</th><th class="r">Paid</th></tr></thead><tbody>';
        foreach (self::for($b) as $e) {
            $out .= '<tr><td>'.e($e['at']->format('d M Y, H:i')).'</td><td>'.e($e['what'])
                .($e['who'] ? ' <span>· '.e($e['who']).'</span>' : '')
                .($e['note'] ? '<br><span>'.e($e['note']).'</span>' : '')
                .'</td><td class="r">'.e($e['due'] === null ? '' : ($e['due'] > 0 && $e['what'] !== 'Booking created' ? '+' : '').$aed($e['due'])).'</td><td class="r">'.e($aed($e['paid'])).'</td></tr>';
        }
        $out .= '</tbody><tfoot><tr><td colspan="2">Now: due AED '.number_format($b->amountDue(), 2).' − received AED '.number_format((float) $b->paid_amount, 2).'</td><td colspan="2" class="r"><strong>Balance AED '.number_format((float) $b->balance, 2).'</strong></td></tr></tfoot></table>';

        return $out;
    }
}
