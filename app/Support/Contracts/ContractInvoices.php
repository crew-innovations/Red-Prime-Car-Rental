<?php

namespace App\Support\Contracts;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\RentalContract;
use App\Models\Setting;
use App\Support\Tax;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 18 Nov: monthly / long-term contracts get one invoice per period (like AppicFleet's contract
 * invoices). The amounts are the booking's own price spread over its days, so the periods always
 * add up to the booking total; the last period takes whatever is left. Each period is invoiced
 * once (unique contract + period start).
 */
class ContractInvoices
{
    /** In advance: the first period is invoiced at the start; in arrears: at the end of the first period. */
    public static function firstInvoiceDate(RentalContract $c): ?Carbon
    {
        $b = $c->booking;
        if (! $b?->start_at) {
            return null;
        }

        return Setting::read('contract_invoice_mode', 'advance') === 'arrears'
            ? self::periodEnd($b->start_at->copy()->startOfDay(), $b)
            : $b->start_at->copy()->startOfDay();
    }

    /** [start, end] of the period that starts on $start (a month, or up to the return). */
    public static function periodEnd(Carbon $start, Booking $b): Carbon
    {
        $end = $start->copy()->addMonthNoOverflow()->subDay();
        $last = $b->end_at->copy()->startOfDay();

        return $end->gt($last) ? $last : $end;
    }

    /** The next period not invoiced yet: [start, end] or null when the whole rental is invoiced. */
    public static function nextPeriod(RentalContract $c): ?array
    {
        $b = $c->booking;
        if (! $b?->start_at || ! $b->end_at) {
            return null;
        }
        $lastEnd = Invoice::where('rental_contract_id', $c->id)->where('status', 'issued')->where('type', 'invoice')->max('period_end');
        $start = $lastEnd ? Carbon::parse($lastEnd)->addDay() : $b->start_at->copy()->startOfDay();
        if ($start->gt($b->end_at->copy()->startOfDay())) {
            return null;
        }

        return [$start, self::periodEnd($start, $b)];
    }

    /** Issue the next period's invoice (once). */
    public static function issueNext(RentalContract $c, ?string $notes = null): ?Invoice
    {
        $b = $c->booking;
        $period = self::nextPeriod($c);
        if (! $b || ! $period) {
            return null;
        }
        [$start, $end] = $period;

        return DB::transaction(function () use ($c, $b, $start, $end, $notes) {
            $totalDays = max(1, $b->start_at->copy()->startOfDay()->diffInDays($b->end_at->copy()->startOfDay()) + 1);
            $days = $start->diffInDays($end) + 1;
            $isLast = $end->gte($b->end_at->copy()->startOfDay());
            $net = round((float) $b->base_amount + (float) $b->extras_amount - (float) $b->discount, 2);
            $invoicedNet = (float) Invoice::where('rental_contract_id', $c->id)->where('status', 'issued')->where('type', 'invoice')->sum('subtotal');
            $periodNet = $isLast ? round($net - $invoicedNet, 2) : round($net * $days / $totalDays, 2);
            [$tax, $total] = Tax::apply(max(0, $periodNet), $b->tax_rate !== null ? (float) $b->tax_rate : null, (bool) $b->tax_inclusive);
            $label = 'Car rental '.$start->format('d M Y').' – '.$end->format('d M Y').' · '.($b->bookable?->name ?? '').' · contract '.$c->number;

            $invoice = Invoice::create([
                'type' => 'invoice',
                'kind' => 'period',
                'number' => Invoice::nextNumber('invoice'),
                'booking_id' => $b->id,
                'customer_id' => $b->customer_id,
                'rental_contract_id' => $c->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'due_on' => today()->addDays(max(0, (int) Setting::read('contract_invoice_due_days', 7)))->toDateString(),
                'issued_on' => today(),
                'supply_date' => $start->toDateString(),
                'is_tax_invoice' => Tax::enabled() && $tax > 0,
                'seller' => Invoice::sellerDetails(),
                'buyer' => Invoice::buyerDetails($b->customer),
                'lines' => [['label' => $label, 'qty' => 1, 'unit_price' => $periodNet, 'amount' => $periodNet]],
                'subtotal' => $periodNet,
                'discount' => 0,
                'tax_rate' => $b->tax_rate,
                'tax_inclusive' => (bool) $b->tax_inclusive,
                'tax_amount' => $tax,
                'total' => $total,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $next = $end->copy()->addDay();
            $c->forceFill(['next_invoice_on' => $next->gt($b->end_at->copy()->startOfDay()) ? null
                : (Setting::read('contract_invoice_mode', 'advance') === 'arrears' ? self::periodEnd($next, $b) : $next)])->saveQuietly();

            return $invoice;
        });
    }

    /**
     * Daily job: period invoices that are due, renewal reminders, and auto-renew. Returns counts.
     * Every step is per contract, so one problem does not stop the others.
     */
    public static function runDaily(): array
    {
        $done = ['invoices' => 0, 'reminders' => 0, 'renewed' => 0];

        RentalContract::query()->where('status', 'open')->where('billing_period', 'monthly')
            ->whereNotNull('next_invoice_on')->whereDate('next_invoice_on', '<=', today())
            ->each(function (RentalContract $c) use (&$done) {
                try {
                    if (self::issueNext($c)) {
                        $done['invoices']++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $days = max(0, (int) Setting::read('contract_renewal_days', 7));
        RentalContract::query()->whereIn('status', ['open', 'draft'])
            ->whereHas('booking', fn ($q) => $q->whereBetween('end_at', [now(), now()->addDays($days)->endOfDay()]))
            ->with('booking')->each(function (RentalContract $c) use (&$done) {
                $b = $c->booking;
                if ($c->auto_renew && $b->end_at->lte(now()->addDay())) {
                    try {
                        Contracts::extend($c, $b->end_at->copy()->addMonthNoOverflow()->toDateTimeString(), 'Renewed automatically');
                        $done['renewed']++;

                        return;
                    } catch (\Throwable $e) {
                        $note = 'Could not renew automatically: '.($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
                    }
                }
                \App\Support\Drivers\Notify::staff('manage-contracts', 'Contract ending: '.$c->number,
                    ($b->customer?->name ?? '').' · '.($b->bookable?->name ?? '').' · returns '.$b->end_at->format('d M H:i').'. Extend it or plan the return.'.(isset($note) ? ' '.$note : ''),
                    \App\Filament\Resources\RentalContractResource::getUrl('view', ['record' => $c]), $b->assignee, 'warning', 'contract_ending', 'contract-ending:'.$c->id.':'.$b->end_at->format('Ymd'));
                $done['reminders']++;
            });

        return $done;
    }
}
