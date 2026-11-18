<?php

namespace App\Support\Leads;

use App\Enums\TransactionType;
use App\Models\DepositSettlement;
use App\Models\Lead;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 5 Nov (S3, B.6): one timeline per lead, from the first enquiry to the deposit settled:
 * created, sent to agents, accepted (by whom, after how long), calls and messages, quotes sent /
 * viewed / accepted / declined, bookings, payments, handover, return, deposit settled.
 * Read-only: built from what is already stored.
 */
class LeadTimeline
{
    /** @return Collection<int, array{at: Carbon, title: string, detail: ?string, icon: string, color: string}> newest first */
    public static function for(Lead $lead): Collection
    {
        $e = collect();
        $add = function ($at, string $title, ?string $detail = null, string $icon = 'heroicon-o-clock', string $color = 'gray') use ($e) {
            if ($at) {
                $e->push(['at' => Carbon::parse($at), 'title' => $title, 'detail' => $detail, 'icon' => $icon, 'color' => $color]);
            }
        };

        $add($lead->created_at, 'Lead came in', collect([$lead->leadSource?->name, \App\Support\Leads\LeadIntake::CHANNELS[$lead->channel] ?? null])->filter()->unique()->implode(' · ') ?: null, 'heroicon-o-inbox-arrow-down', 'info');

        foreach ($lead->activities()->with('user')->get() as $a) {
            $type = $a->type;
            $add($a->created_at, $type?->getLabel() === 'Note' || ! $type ? mb_strimwidth((string) $a->body, 0, 120, '…') : $type->getLabel().($a->user ? ' by '.$a->user->name : ''),
                $type?->getLabel() === 'Note' || ! $type ? ($a->user?->name) : mb_strimwidth((string) $a->body, 0, 120, '…'),
                $type?->getIcon() ?? 'heroicon-o-chat-bubble-left', str_contains((string) $a->body, 'Accepted by') ? 'success' : 'gray');
        }

        foreach ($lead->quotations()->withTrashed()->get() as $q) {
            $add($q->sent_at, 'Quotation '.$q->number.' sent', 'AED '.number_format((float) $q->total, 2), 'heroicon-o-paper-airplane', 'info');
            $add($q->viewed_at, 'Quotation '.$q->number.' viewed by the customer', null, 'heroicon-o-eye');
            $add($q->accepted_at, 'Quotation '.$q->number.' accepted', null, 'heroicon-o-hand-thumb-up', 'success');
            $add($q->rejected_at, 'Quotation '.$q->number.' declined', null, 'heroicon-o-hand-thumb-down', 'danger');
        }

        $bookings = $lead->bookings()->withTrashed()->with(['driver'])->get();
        foreach ($bookings as $b) {
            $add($b->created_at, 'Booking '.$b->reference.' made', $b->status?->getLabel(), 'heroicon-o-calendar-days', 'success');
            $add($b->delivered_at, 'Handed over · '.$b->reference, $b->driver ? 'Driver '.$b->driver->name : null, 'heroicon-o-key', 'success');
            $add($b->returned_at, 'Returned · '.$b->reference, null, 'heroicon-o-arrow-uturn-left', 'info');
        }
        if ($bookings->isNotEmpty()) {
            $ids = $bookings->pluck('id');
            Transaction::query()->whereIn('booking_id', $ids)->where('type', TransactionType::Income)->where('is_balance', false)
                ->whereNull('reversed_at')->orderBy('date')->get()
                ->each(fn (Transaction $t) => $add($t->created_at, 'Payment received', 'AED '.number_format((float) $t->amount, 2).($t->description ? ' · '.$t->description : ''), 'heroicon-o-banknotes', 'success'));
            DepositSettlement::query()->whereIn('booking_id', $ids)->get()
                ->each(fn ($s) => $add($s->created_at, 'Deposit settled', null, 'heroicon-o-scale', 'success'));
        }

        return $e->sortByDesc('at')->values();
    }
}
