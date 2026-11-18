<?php

namespace App\Support\Reports;

use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 5 Nov (S3, B.6): what each agent did in a period. Leads accepted and how fast, quotes sent,
 * bookings and conversion, revenue, money collected and still to collect, discounts given,
 * late follow-ups. Read-only; Admin only.
 */
class AgentReport
{
    public function __construct(public Carbon $from, public Carbon $to) {}

    public function agents(): Collection
    {
        return User::query()->whereNull('customer_id')->where('is_system', false)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', Access::withLegacy(['Agent'])))
            ->orderByDesc('is_active')->orderBy('name')->get()
            ->filter(fn (User $u) => Access::rolesOf($u)->contains('Agent') && ! Access::rolesOf($u)->contains('Admin'))
            ->values();
    }

    /** @return list<array<string, mixed>> one row per agent, best revenue first */
    public function rows(): array
    {
        $from = $this->from->copy()->startOfDay();
        $to = $this->to->copy()->endOfDay();

        return $this->agents()->map(function (User $u) use ($from, $to) {
            $accepted = Lead::withTrashed()->where('assigned_to', $u->id)->whereBetween('accepted_at', [$from, $to]);
            $claimed = (clone $accepted)->whereNotNull('broadcast_at')->get(['broadcast_at', 'accepted_at']);
            $avg = $claimed->isEmpty() ? null : (int) round($claimed->avg(fn ($l) => $l->broadcast_at->diffInMinutes($l->accepted_at)));

            $quotes = Quotation::query()->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $to])
                ->where(fn ($q) => $q->whereIn('lead_id', Lead::withTrashed()->where('assigned_to', $u->id)->select('id'))
                    ->orWhere(fn ($m) => $m->whereNull('lead_id')->where('created_by', $u->id)))->count();

            $bookings = Booking::query()->where('assigned_to', $u->id)->whereBetween('created_at', [$from, $to])
                ->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::NoShow]);
            $bookingCount = (clone $bookings)->count();
            $revenue = (float) (clone $bookings)->sum('total');
            $discounts = (float) (clone $bookings)->sum('list_discount_amount');

            $collected = (float) Transaction::query()->where('type', TransactionType::Income)->where('is_balance', false)->whereNull('reversed_at')
                ->where('status', PaymentStatus::Paid)->whereNotIn('category', Transaction::NON_REVENUE_CATEGORIES)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('booking_id', Booking::withTrashed()->where('assigned_to', $u->id)->select('id'))->sum('amount');

            $toCollect = (float) Booking::query()->where('assigned_to', $u->id)
                ->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed])
                ->where('balance', '>', 0)->sum('balance');

            $late = Lead::query()->where('assigned_to', $u->id)->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Lost->value])
                ->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now())->count();

            $leadCount = (clone $accepted)->count();

            return [
                'user' => $u,
                'leads' => $leadCount,
                'avg_minutes' => $avg,
                'quotes' => $quotes,
                'bookings' => $bookingCount,
                'conversion' => $leadCount > 0 ? round($bookingCount / $leadCount * 100) : null,
                'revenue' => $revenue,
                'collected' => $collected,
                'to_collect' => $toCollect,
                'discounts' => $discounts,
                'late' => $late,
            ];
        })->sortByDesc('revenue')->values()->all();
    }
}
