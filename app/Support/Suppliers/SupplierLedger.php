<?php

namespace App\Support\Suppliers;

use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Transaction;
use App\Support\Access;
use App\Support\Drivers\Notify;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier bills, payments and balances, inside the existing Accounts (29 Oct, 15–19).
 *
 *  - A bill is an expense row (category "Partner payout") with the supplier on it, so it
 *    counts as a cost in every existing report, once. It follows the existing expense
 *    approval rule; a bill waiting for approval is not owed yet.
 *  - A payment settles one or more bills (or the opening balance). It is NOT an expense,
 *    so nothing is counted twice.
 *  - Balance = opening balance + approved bills − payments (not reversed).
 *    Positive = RED PRIME owes the supplier.
 */
class SupplierLedger
{
    public const CATEGORY = 'partner_payout';

    /** Set while this class changes a bill's paid status (other code may not). */
    public static bool $writing = false;

    // ---------------------------------------------------------------- bills

    /** Job completed (Bilal, 29 Oct): post the supplier bill once from the booking's cost. */
    public static function postForBooking(Booking $b, string $why = 'Job completed'): ?Transaction
    {
        if (SupplierCost::billPosted($b)) {
            return Transaction::find($b->supplier_bill_id);
        }
        if (! $b->supplier_id || ! in_array($b->supplier_cost_status, ['calculated', 'override'], true) || $b->supplier_cost === null) {
            if ($b->supplier_cost_status) {
                rescue(fn () => Notify::staff('manage-finance', 'Supplier bill not posted: '.$b->reference,
                    (string) (data_get($b->supplier_cost_snapshot, 'reason') ?: 'The supplier cost is not known yet.').' Set it on the booking, then post the bill.',
                    \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $b]), null, 'warning', event: 'other'), report: false);
            }

            return null;
        }
        if ((float) $b->supplier_cost <= 0) {
            return null; // approved zero cost: nothing owed
        }

        return DB::transaction(function () use ($b, $why) {
            $locked = Booking::query()->lockForUpdate()->find($b->id);
            if (SupplierCost::billPosted($locked)) {
                return Transaction::find($locked->supplier_bill_id);
            }
            // A bill for the same booking and supplier typed by hand counts as the bill: never a second one.
            $existing = Transaction::query()->expense()->where('booking_id', $b->id)->where('supplier_id', $b->supplier_id)
                ->where('category', self::CATEGORY)->whereNull('reversed_at')->first();
            $bill = $existing ?? Transaction::query()->create([
                'type' => TransactionType::Expense,
                'category' => self::CATEGORY,
                'booking_id' => $b->id,
                'supplier_id' => $b->supplier_id,
                'date' => today(),
                'amount' => round((float) $b->supplier_cost, 2),
                'status' => PaymentStatus::Pending,
                'reference' => 'Rate #'.(data_get($b->supplier_cost_snapshot, 'rate_id') ?? 'override'),
                'description' => mb_substr('Supplier bill · '.(data_get($b->supplier_cost_snapshot, 'supplier') ?? 'Supplier').' · '.$b->reference.' · '.(data_get($b->supplier_cost_snapshot, 'vehicle') ?? ''), 0, 255),
                'notes' => self::explain((array) $b->supplier_cost_snapshot),
            ]);
            $locked->forceFill(['supplier_bill_id' => $bill->id])->saveQuietly();
            AuditLog::note($b, 'supplier_bill', 'Supplier bill AED '.number_format((float) $bill->amount, 2).' posted ('.$why.').');

            return $bill;
        });
    }

    public static function explain(array $s): string
    {
        if (isset($s['override'])) {
            return 'Set by '.($s['override']['by_name'] ?? 'Finance').' on '.($s['override']['at'] ?? '').': '.($s['override']['reason'] ?? '')
                .(isset($s['override']['calculated']) ? ' (calculated AED '.number_format((float) $s['override']['calculated'], 2).')' : '');
        }

        return trim(($s['qty_label'] ?? '').' × '.(\App\Models\SupplierRate::METHODS[$s['method'] ?? ''] ?? '').' '.(isset($s['rate']) ? ($s['method'] === 'percent' ? $s['rate'].'%' : 'AED '.number_format((float) $s['rate'], 2)) : '')
            .' ('.(($s['source'] ?? '') === 'vehicle' ? 'vehicle rate' : 'supplier default').(isset($s['rate_starts_on']) ? ' from '.$s['rate_starts_on'] : '').')');
    }

    /** Bills that can be paid: approved, not reversed, something still owed. */
    public static function openBills(Supplier $s): Collection
    {
        return Transaction::query()->expense()->counting()->where('supplier_id', $s->id)->where('status', '<>', PaymentStatus::Refunded->value)
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn (Transaction $t) => tap($t, fn ($t) => $t->setAttribute('outstanding', self::outstanding($t))))
            ->filter(fn ($t) => $t->outstanding > 0.004)->values();
    }

    public static function paidOn(Transaction $t): float
    {
        return round((float) SupplierPaymentAllocation::query()->where('transaction_id', $t->id)
            ->whereHas('payment', fn ($q) => $q->whereNull('reversed_at'))->sum('amount'), 2);
    }

    public static function outstanding(Transaction $t): float
    {
        return max(0, round((float) $t->amount - self::paidOn($t), 2));
    }

    public static function openingOutstanding(Supplier $s): float
    {
        $paid = (float) SupplierPaymentAllocation::query()->whereNull('transaction_id')
            ->whereHas('payment', fn ($q) => $q->where('supplier_id', $s->id)->whereNull('reversed_at'))->sum('amount');

        return max(0, round((float) $s->opening_balance - $paid, 2));
    }

    /** @return array{opening: float, bills: float, payments: float, balance: float, waiting_approval: float} */
    public static function balance(Supplier $s): array
    {
        $bills = round((float) Transaction::query()->expense()->counting()->where('supplier_id', $s->id)->where('status', '<>', PaymentStatus::Refunded->value)->sum('amount'), 2);
        $payments = round((float) SupplierPayment::query()->where('supplier_id', $s->id)->whereNull('reversed_at')->sum('amount'), 2);
        $waiting = round((float) Transaction::query()->expense()->where('supplier_id', $s->id)->whereNull('reversed_at')->where('approval_status', 'pending')->sum('amount'), 2);
        $opening = round((float) $s->opening_balance, 2);

        return ['opening' => $opening, 'bills' => $bills, 'payments' => $payments, 'balance' => round($opening + $bills - $payments, 2), 'waiting_approval' => $waiting];
    }

    /** Everything owed to all suppliers (Accounts › To pay). */
    public static function totalOwed(): float
    {
        return round(Supplier::query()->get()->sum(fn (Supplier $s) => self::balance($s)['balance']), 2);
    }

    /** Statement lines with a running balance. */
    public static function statement(Supplier $s, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $lines = collect();
        $bills = Transaction::query()->expense()->counting()->where('supplier_id', $s->id)->where('status', '<>', PaymentStatus::Refunded->value)->with('booking')->get();
        foreach ($bills as $t) {
            $lines->push(['date' => $t->date, 'kind' => 'bill', 'ref' => $t->booking?->reference ?? ('#'.$t->id), 'text' => (string) $t->description, 'debit' => (float) $t->amount, 'credit' => 0.0, 'id' => $t->id]);
        }
        foreach (SupplierPayment::query()->where('supplier_id', $s->id)->whereNull('reversed_at')->get() as $p) {
            $lines->push(['date' => $p->date, 'kind' => 'payment', 'ref' => $p->number, 'text' => trim('Payment '.($p->method ? '· '.str_replace('_', ' ', $p->method) : '').' '.($p->reference ? '· '.$p->reference : '')), 'debit' => 0.0, 'credit' => (float) $p->amount, 'id' => $p->id]);
        }
        $lines = $lines->sortBy(fn ($l) => $l['date']->format('Ymd').($l['kind'] === 'bill' ? '0' : '1').str_pad((string) $l['id'], 10, '0', STR_PAD_LEFT))->values();

        $running = (float) $s->opening_balance;
        $before = $running;
        $out = [];
        foreach ($lines as $l) {
            $running = round($running + $l['debit'] - $l['credit'], 2);
            if ($from && $l['date']->lt($from)) {
                $before = $running;

                continue;
            }
            if ($to && $l['date']->gt($to)) {
                continue;
            }
            $out[] = $l + ['balance' => $running];
        }

        return ['opening' => round($from ? $before : (float) $s->opening_balance, 2), 'lines' => $out, 'closing' => round(end($out) ? end($out)['balance'] : ($from ? $before : (float) $s->opening_balance), 2)];
    }

    // ---------------------------------------------------------------- payments

    /**
     * Records a payment. $allocations: [bill id => amount, 'opening' => amount]; the total must equal $amount.
     * Never more than what is owed on each line.
     */
    public static function pay(Supplier $s, float $amount, string $date, array $allocations, ?string $method = null, ?string $reference = null, ?string $notes = null): SupplierPayment
    {
        abort_unless(Access::allows('manage-finance'), 403);
        $amount = round($amount, 2);
        $allocations = collect($allocations)->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0);
        $fail = fn (string $m) => throw ValidationException::withMessages(['data.amount' => $m]);

        if ($amount <= 0) {
            $fail('Enter an amount above zero.');
        }
        if ($allocations->isEmpty()) {
            $fail('Choose the bills this payment settles.');
        }
        if (abs($allocations->sum() - $amount) > 0.004) {
            $fail('The amounts against the bills (AED '.number_format($allocations->sum(), 2).') must add up to the payment (AED '.number_format($amount, 2).').');
        }
        if (Carbon::parse($date)->isAfter(today()->addDay())) {
            $fail('The payment date can\'t be in the future.');
        }

        return DB::transaction(function () use ($s, $amount, $date, $allocations, $method, $reference, $notes, $fail) {
            Supplier::query()->lockForUpdate()->find($s->id);
            // The same payment saved twice (double click, retry) is refused.
            $twin = SupplierPayment::query()->where('supplier_id', $s->id)->whereNull('reversed_at')->where('amount', $amount)->whereDate('date', $date)
                ->where('created_by', auth()->id())->where('created_at', '>=', now()->subMinutes(2))
                ->when($reference, fn ($q) => $q->where('reference', $reference))->exists();
            if ($twin) {
                $fail('This payment was just recorded. Check the payments list before adding it again.');
            }

            foreach ($allocations as $key => $value) {
                if ($key === 'opening') {
                    if ($value > self::openingOutstanding($s) + 0.004) {
                        $fail('Only AED '.number_format(self::openingOutstanding($s), 2).' of the opening balance is still owed.');
                    }

                    continue;
                }
                $bill = Transaction::query()->lockForUpdate()->find((int) $key);
                if (! $bill || (int) $bill->supplier_id !== (int) $s->id || $bill->type !== TransactionType::Expense) {
                    $fail('A chosen bill does not belong to this supplier.');
                }
                if ($bill->reversed_at || $bill->trashed() || ! in_array($bill->approval_status, [null, 'approved'], true) || $bill->is_estimate) {
                    $fail('Bill #'.$bill->id.' can\'t be paid (reversed, an estimate or waiting for approval).');
                }
                if ($value > self::outstanding($bill) + 0.004) {
                    $fail('Bill #'.$bill->id.': only AED '.number_format(self::outstanding($bill), 2).' is still owed.');
                }
            }

            $p = SupplierPayment::query()->create(['supplier_id' => $s->id, 'date' => $date, 'amount' => $amount, 'method' => $method, 'reference' => $reference ? mb_substr($reference, 0, 255) : null, 'notes' => $notes]);
            foreach ($allocations as $key => $value) {
                $p->allocations()->create(['transaction_id' => $key === 'opening' ? null : (int) $key, 'amount' => $value]);
            }
            self::refreshBills($allocations->keys()->reject(fn ($k) => $k === 'opening')->all());
            AuditLog::note($s, 'supplier_payment', 'Payment '.$p->fresh()->number.' AED '.number_format($amount, 2).' to '.$s->name.($reference ? ' ('.$reference.')' : ''));

            return $p->fresh();
        });
    }

    /** Oldest first: opening balance, then bills by date. */
    public static function suggest(Supplier $s, float $amount): array
    {
        $left = round($amount, 2);
        $out = [];
        if (($o = self::openingOutstanding($s)) > 0 && $left > 0) {
            $out['opening'] = min($o, $left);
            $left = round($left - $out['opening'], 2);
        }
        foreach (self::openBills($s) as $bill) {
            if ($left <= 0) {
                break;
            }
            $out[$bill->id] = min($bill->outstanding, $left);
            $left = round($left - $out[$bill->id], 2);
        }

        return $out;
    }

    public static function reverse(SupplierPayment $p, string $reason): void
    {
        abort_unless(Access::allows('manage-finance'), 403);
        if ($p->reversed_at) {
            return;
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['data.reason' => 'Say why.']);
        }
        DB::transaction(function () use ($p, $reason) {
            $p->forceFill(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reverse_reason' => mb_substr($reason, 0, 255)])->save();
            self::refreshBills($p->allocations()->whereNotNull('transaction_id')->pluck('transaction_id')->all());
            AuditLog::note($p->supplier, 'supplier_payment', 'Payment '.$p->number.' reversed: '.$reason);
        });
    }

    /** A bill's paid status follows its payments. */
    public static function refreshBills(array $ids): void
    {
        self::$writing = true;
        try {
            foreach (Transaction::query()->whereIn('id', $ids)->get() as $bill) {
                $paid = self::paidOn($bill);
                $bill->forceFill([
                    'amount_paid' => $paid > 0 ? $paid : null,
                    'status' => $paid <= 0 ? PaymentStatus::Pending : ($paid >= (float) $bill->amount - 0.004 ? PaymentStatus::Paid : PaymentStatus::PartiallyPaid),
                ])->save();
            }
        } finally {
            self::$writing = false;
        }
    }

    /** Manual bill from the supplier page (e.g. a monthly invoice). */
    public static function addBill(Supplier $s, float $amount, string $date, string $description, ?int $bookingId = null, ?string $dueDate = null, ?string $reference = null): Transaction
    {
        abort_unless(Access::allows('manage-finance'), 403);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['data.amount' => 'Enter an amount above zero.']);
        }
        return DB::transaction(function () use ($s, $amount, $date, $description, $bookingId, $dueDate, $reference) {
            if ($bookingId) {
                $b = Booking::query()->lockForUpdate()->find($bookingId);
                if (! $b || (int) $b->supplier_id !== (int) $s->id) {
                    throw ValidationException::withMessages(['data.booking_id' => 'This booking does not use this supplier.']);
                }
                if (SupplierCost::billPosted($b)) {
                    throw ValidationException::withMessages(['data.booking_id' => 'This booking already has its supplier bill. Reverse it first if it is wrong.']);
                }
            }
            $bill = Transaction::query()->create([
                'type' => TransactionType::Expense, 'category' => self::CATEGORY, 'supplier_id' => $s->id, 'booking_id' => $bookingId,
                'date' => $date, 'due_date' => $dueDate, 'amount' => round($amount, 2), 'status' => PaymentStatus::Pending,
                'reference' => $reference, 'description' => mb_substr('Supplier bill · '.$s->name.' · '.$description, 0, 255),
            ]);
            if (isset($b)) {
                $b->forceFill(['supplier_bill_id' => $bill->id])->saveQuietly();
            }

            return $bill;
        });
    }
}
