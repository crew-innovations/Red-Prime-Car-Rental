<?php

namespace App\Support\Accounts;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit and money reports (28 Oct brief, Priority 8.3). One place for the
 * definitions, so every screen agrees. Every figure can be traced: the rows
 * carry the booking and money-row ids they are built from.
 *
 * Definitions (also shown on the screen):
 * - Earned revenue: price of rentals / charters / trips that were completed
 *   in the period (by return date), without VAT, plus return charges. Money
 *   kept on cancelled or no-show bookings counts as earned when it was received.
 * - Cash received: payments received in the period (not security deposits).
 * - Direct costs: actual, approved expenses linked to those bookings.
 * - Contribution: earned revenue minus direct costs.
 * - Net result: earned revenue minus all actual, approved expenses dated in
 *   the period. It is only complete if every cost is entered; there are no
 *   allocations (rent, salaries…) unless they are entered as expenses.
 * Estimates, unapproved and reversed rows never count; they are shown apart.
 */
class ProfitReport
{
    public function __construct(public Carbon $from, public Carbon $to) {}

    public static function forRange($from, $to): self
    {
        return new self(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay());
    }

    /** Bookings whose revenue is earned in the period. */
    public function earnedBookings(): Collection
    {
        $completed = Booking::query()->with(['bookable', 'assignee'])
            ->where('status', BookingStatus::Completed)
            // Older bookings marked completed without a return time count on their end date.
            ->whereRaw('COALESCE(returned_at, end_at) BETWEEN ? AND ?', [$this->from, $this->to])
            ->get();

        $kept = Booking::query()->with(['bookable', 'assignee'])
            ->whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])
            ->whereBetween('end_at', [$this->from, $this->to])
            ->where('paid_amount', '>', 0)
            ->get();

        return $completed->concat($kept);
    }

    /** Revenue a booking earns, without VAT (and without deposits). */
    public static function earnedFor(Booking $b): float
    {
        if (in_array($b->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            return round((float) $b->paid_amount, 2); // money kept
        }

        return round((float) $b->total - (float) $b->tax_amount + (float) $b->extra_charges, 2);
    }

    /** Actual, approved costs of these bookings (any date). booking_id => amount */
    public static function directCosts(array $bookingIds): Collection
    {
        if (! $bookingIds) {
            return collect();
        }

        return Transaction::query()->counting()->expense()
            ->whereIn('booking_id', $bookingIds)
            ->where('status', '<>', PaymentStatus::Refunded)
            ->whereNotIn('category', Transaction::NON_COST_CATEGORIES)
            ->selectRaw('booking_id, SUM(amount) as total')
            ->groupBy('booking_id')
            ->pluck('total', 'booking_id')
            ->map(fn ($v) => round((float) $v, 2));
    }

    public function summary(): array
    {
        $earned = $this->earnedBookings();
        $earnedTotal = round($earned->sum(fn (Booking $b) => self::earnedFor($b)), 2);
        $direct = round(self::directCosts($earned->pluck('id')->all())->sum(), 2);
        $period = Transaction::query()->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()]);
        $totals = Transaction::totals(clone $period);

        $expensesBy = (clone $period)->counting()->expense()->where('status', '<>', PaymentStatus::Refunded)
            ->whereNotIn('category', Transaction::NON_COST_CATEGORIES)
            ->selectRaw('category, SUM(amount) as total')->groupBy('category')->orderByDesc('total')
            ->pluck('total', 'category')->map(fn ($v) => round((float) $v, 2));

        $estimates = round((float) (clone $period)->expense()->where('is_estimate', true)->whereNull('reversed_at')->sum('amount'), 2);
        $pending = round((float) Transaction::query()->expense()->where('approval_status', 'pending')->whereNull('reversed_at')->sum('amount'), 2);
        $reversed = (clone $period)->whereNotNull('reversed_at')->count();
        $refunds = round((float) (clone $period)->income()->where('status', PaymentStatus::Refunded)->whereNull('reversed_at')->sum('amount'), 2);

        $deposits = Transaction::query()->counting()->where('status', PaymentStatus::Paid);
        $held = round((float) (clone $deposits)->income()->where('category', 'deposit')->sum('amount') - (float) (clone $deposits)->expense()->where('category', 'deposit_refund')->sum('amount'), 2);
        $depositRefunds = round((float) (clone $period)->counting()->expense()->where('category', 'deposit_refund')->where('status', PaymentStatus::Paid)->sum('amount'), 2);

        $quoted = round((float) Quotation::query()->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation, QuotationStatus::Accepted, QuotationStatus::Expired, QuotationStatus::Rejected])
            ->whereBetween('sent_at', [$this->from, $this->to])->sum('total'), 2);
        $confirmed = round((float) Booking::query()->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed])
            ->whereBetween('start_at', [$this->from, $this->to])->get()->sum(fn (Booking $b) => $b->amountDue()), 2);
        $invoiced = round((float) Invoice::query()->where('status', 'issued')->where('type', 'invoice')->whereBetween('issued_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total')
            - (float) Invoice::query()->where('status', 'issued')->where('type', 'credit_note')->whereBetween('issued_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total'), 2);
        $charges = round($earned->sum(fn (Booking $b) => (float) $b->extra_charges), 2);
        $expenses = round($expensesBy->sum(), 2);

        return [
            'quoted' => $quoted,
            'confirmed' => $confirmed,
            'earned' => $earnedTotal,
            'earned_count' => $earned->count(),
            'extra_charges' => $charges,
            'invoiced' => $invoiced,
            'received' => round($totals['received'], 2),
            'outstanding' => round((float) Transaction::totals(Transaction::query())['outstanding'], 2),
            'deposits_held' => $held,
            'deposit_refunds' => $depositRefunds,
            'refunds' => $refunds,
            'reversed_rows' => $reversed,
            'direct_costs' => $direct,
            'contribution' => round($earnedTotal - $direct, 2),
            'expenses' => $expenses,
            'expenses_by' => $expensesBy->all(),
            'estimates' => $estimates,
            'pending_approval' => $pending,
            'net' => round($earnedTotal - $expenses, 2),
        ];
    }

    /**
     * Rows for one view. Each row: key, label, url, earned, received, costs, contribution, bookings (ids), expenses (ids).
     *
     * @param  string  $by  booking | car | yacht | agent | service | supplier | month | category
     */
    public function rows(string $by): array
    {
        $earned = $this->earnedBookings();
        $direct = self::directCosts($earned->pluck('id')->all());

        if ($by === 'category') {
            return $this->categoryRows();
        }
        if ($by === 'month') {
            return $this->monthRows($earned);
        }

        $groups = match ($by) {
            'car' => $earned->filter(fn (Booking $b) => $b->bookable_type === 'car')->groupBy('bookable_id'),
            'yacht' => $earned->filter(fn (Booking $b) => $b->bookable_type === 'yacht')->groupBy('bookable_id'),
            'agent' => $earned->groupBy(fn (Booking $b) => (int) $b->assigned_to),
            'service' => $earned->groupBy(fn (Booking $b) => $b->revenueCategory()),
            'supplier' => $earned->groupBy(fn (Booking $b) => (int) $b->supplier_id),
            default => $earned->keyBy('id')->map(fn ($b) => collect([$b])),
        };

        $rows = $groups->map(function (Collection $bookings, $key) use ($by, $direct) {
            $first = $bookings->first();
            $ids = $bookings->pluck('id')->all();
            $earnedSum = round($bookings->sum(fn (Booking $b) => self::earnedFor($b)), 2);
            $costs = round(collect($ids)->sum(fn ($id) => (float) ($direct[$id] ?? 0)), 2);

            // Cars and yachts also carry their own costs dated in the period (service, insurance…), not linked to a booking.
            $ownCosts = 0.0;
            if (in_array($by, ['car', 'yacht'], true)) {
                $ownCosts = round((float) Transaction::query()->counting()->expense()->whereNull('booking_id')
                    ->where('related_type', $by)->where('related_id', $key)
                    ->where('status', '<>', PaymentStatus::Refunded)->whereNotIn('category', Transaction::NON_COST_CATEGORIES)
                    ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])->sum('amount'), 2);
            }

            [$label, $url] = match ($by) {
                'car' => [$first->bookable?->name ?? 'Car #'.$key, $first->bookable ? \App\Filament\Resources\CarResource::getUrl('edit', ['record' => $key]) : null],
                'yacht' => [$first->bookable?->name ?? 'Yacht #'.$key, $first->bookable ? \App\Filament\Resources\YachtResource::getUrl('edit', ['record' => $key]) : null],
                'agent' => [$first->assignee?->name ?? 'Not assigned', null],
                'service' => [Transaction::INCOME_CATEGORIES[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)), null],
                'supplier' => $key ? [$first->supplier?->name ?? 'Supplier #'.$key, \App\Filament\Resources\SupplierResource::getUrl('edit', ['record' => $key])] : ['Own vehicles', null],
                default => [$first->reference.' · '.($first->bookable?->name ?? $first->service_type?->getLabel()), \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $first])],
            };

            return [
                'key' => (string) $key,
                'label' => $label,
                'url' => $url,
                'count' => count($ids),
                'earned' => $earnedSum,
                'received' => round($bookings->sum(fn (Booking $b) => (float) $b->paid_amount), 2),
                'costs' => round($costs + $ownCosts, 2),
                'contribution' => round($earnedSum - $costs - $ownCosts, 2),
                'bookings' => $ids,
            ];
        })->sortByDesc('earned')->values()->all();

        return $rows;
    }

    protected function monthRows(Collection $earned): array
    {
        $months = [];
        for ($m = $this->from->copy()->startOfMonth(); $m->lte($this->to); $m->addMonthNoOverflow()) {
            $months[$m->format('Y-m')] = ['key' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'url' => null, 'count' => 0, 'earned' => 0.0, 'received' => 0.0, 'costs' => 0.0, 'contribution' => 0.0, 'bookings' => []];
        }
        foreach ($earned as $b) {
            $k = ($b->returned_at ?? $b->end_at)->format('Y-m');
            if (isset($months[$k])) {
                $months[$k]['earned'] += self::earnedFor($b);
                $months[$k]['count']++;
                $months[$k]['bookings'][] = $b->id;
            }
        }
        $period = Transaction::query()->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()]);
        $cash = (clone $period)->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, ".Transaction::receivedSql().' as received, '.Transaction::expensesSql().' as expenses')
            ->groupBy('ym')->toBase()->get()->keyBy('ym');
        foreach ($months as $k => &$row) {
            $row['received'] = round((float) ($cash[$k]->received ?? 0), 2);
            $row['costs'] = round((float) ($cash[$k]->expenses ?? 0), 2);
            $row['earned'] = round($row['earned'], 2);
            $row['contribution'] = round($row['earned'] - $row['costs'], 2);
        }

        return array_values($months);
    }

    protected function categoryRows(): array
    {
        $period = Transaction::query()->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])->counting()
            ->where('status', '<>', PaymentStatus::Refunded);

        $income = (clone $period)->income()->whereNotIn('category', Transaction::NON_REVENUE_CATEGORIES)
            ->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyPaid])
            ->selectRaw("category, SUM(CASE status WHEN 'paid' THEN amount ELSE COALESCE(amount_paid, 0) END) as total")->groupBy('category')->pluck('total', 'category');
        $expense = (clone $period)->expense()->whereNotIn('category', Transaction::NON_COST_CATEGORIES)
            ->selectRaw('category, SUM(amount) as total')->groupBy('category')->pluck('total', 'category');

        $labels = Transaction::categories();
        $rows = [];
        foreach ($income as $cat => $total) {
            $rows[] = ['key' => 'in:'.$cat, 'label' => ($labels[$cat] ?? $cat).' (received)', 'url' => null, 'count' => null, 'earned' => null, 'received' => round((float) $total, 2), 'costs' => 0.0, 'contribution' => round((float) $total, 2), 'bookings' => []];
        }
        foreach ($expense as $cat => $total) {
            $rows[] = ['key' => 'out:'.$cat, 'label' => ($labels[$cat] ?? $cat), 'url' => null, 'count' => null, 'earned' => null, 'received' => 0.0, 'costs' => round((float) $total, 2), 'contribution' => round(-(float) $total, 2), 'bookings' => []];
        }

        return $rows;
    }
}
