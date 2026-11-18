<?php

namespace App\Support\Reports;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Discounts given" (upgrade brief 4 Nov, 10): what was taken off the price list,
 * by agent, service or month — count, total discount and average %.
 *
 * Bookings: not cancelled, by booking date (created). Quotations: sent, by the
 * date they were sent. The discount is the price list minus the agreed price
 * before VAT, saved on each record when its price is set (list_discount_amount).
 */
class DiscountReport
{
    public const GROUPS = ['agent' => 'Agent', 'service' => 'Service', 'month' => 'Month'];

    public const SOURCES = ['bookings' => 'Bookings', 'quotations' => 'Quotations sent'];

    public function __construct(public Carbon $from, public Carbon $to, public string $source = 'bookings') {}

    public function records(): Collection
    {
        if ($this->source === 'quotations') {
            return Access::scopeOwn(Quotation::query(), 'quotation')
                ->whereNotNull('sent_at')
                ->whereBetween('sent_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
                ->whereNot('status', QuotationStatus::Draft)
                ->get(['id', 'number', 'created_by', 'adjusted_by', 'service_type', 'sent_at', 'standard_amount', 'list_discount_amount', 'list_discount_percent', 'total'])
                ->map(fn (Quotation $q) => [
                    'agent' => $q->adjusted_by ?: $q->created_by, 'service' => $q->service_type?->getLabel() ?? '—',
                    'month' => $q->sent_at->format('Y-m'), 'list' => (float) $q->standard_amount,
                    'off' => (float) $q->list_discount_amount, 'percent' => (float) $q->list_discount_percent,
                ]);
        }

        return Access::scopeOwn(Booking::query(), 'booking')
            ->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::Inquiry])
            ->whereBetween('created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->get(['id', 'reference', 'assigned_to', 'adjusted_by', 'service_type', 'created_at', 'standard_amount', 'list_discount_amount', 'list_discount_percent', 'total'])
            ->map(fn (Booking $b) => [
                'agent' => $b->adjusted_by ?: $b->assigned_to, 'service' => $b->service_type?->getLabel() ?? '—',
                'month' => $b->created_at->format('Y-m'), 'list' => (float) $b->standard_amount,
                'off' => (float) $b->list_discount_amount, 'percent' => (float) $b->list_discount_percent,
            ]);
    }

    /** @return list<array{key: string, label: string, count: int, discounted: int, total: float, average: float, list: float}> */
    public function rows(string $by): array
    {
        $by = array_key_exists($by, self::GROUPS) ? $by : 'agent';
        $records = $this->records();
        $names = $by === 'agent' ? User::whereIn('id', $records->pluck('agent')->filter()->unique())->pluck('name', 'id') : collect();

        return $records->groupBy(fn ($r) => (string) ($r[$by] ?? ''))
            ->map(function (Collection $rows, string $key) use ($by, $names) {
                $given = $rows->filter(fn ($r) => $r['off'] > 0);

                return [
                    'key' => $key,
                    'label' => match ($by) {
                        'agent' => $key !== '' ? ($names[$key] ?? 'User #'.$key) : 'Nobody',
                        'month' => Carbon::createFromFormat('Y-m', $key)->format('M Y'),
                        default => $key,
                    },
                    'count' => $rows->count(),
                    'discounted' => $given->count(),
                    'total' => round($given->sum('off'), 2),
                    // Average % over the discounted ones, weighted by list price (a 50% on a small extra doesn't hide a big one).
                    'average' => $given->sum('list') > 0 ? round($given->sum('off') / $given->sum('list') * 100, 2) : 0.0,
                    'list' => round($given->sum('list'), 2),
                ];
            })
            ->sortBy($by === 'month' ? 'key' : fn ($r) => -$r['total'])
            ->values()->all();
    }
}
