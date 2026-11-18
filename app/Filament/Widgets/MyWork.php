<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Quotation;
use App\Support\Access;
use Filament\Widgets\Widget;

/**
 * The agent's workspace (28 Oct brief, Priority 1.3): the signed-in person's own
 * next steps, from a new enquiry to a booking that is paid. Every line opens the record.
 */
class MyWork extends Widget
{
    protected static string $view = 'filament.widgets.my-work';

    protected static ?int $sort = -6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user || Access::isDriverOnly()) {
            return false;
        }

        return \App\Support\Access::rolesOf($user)->contains('Agent')
            || Lead::query()->where('assigned_to', $user->id)->open()->exists()
            || Quotation::query()->where('created_by', $user->id)->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Negotiation, QuotationStatus::Accepted])->exists();
    }

    protected function getViewData(): array
    {
        $me = auth()->id();
        $lead = fn (Lead $l, string $note) => ['title' => $l->name, 'sub' => $note, 'url' => LeadResource::getUrl('edit', ['record' => $l])];
        $quote = fn (Quotation $q, string $note) => ['title' => $q->title(), 'sub' => collect([$q->customerName(), $note])->filter()->implode(' · '), 'url' => QuotationResource::getUrl('edit', ['record' => $q])];
        $booking = fn (Booking $b, string $note) => ['title' => $b->reference.' · '.($b->bookable?->name ?? $b->service_type?->getLabel()), 'sub' => collect([$b->customer?->name, $note])->filter()->implode(' · '), 'url' => BookingResource::getUrl('edit', ['record' => $b])];
        $mine = fn ($q) => $q->where(fn ($w) => $w->where('created_by', $me)->orWhereIn('lead_id', fn ($s) => $s->select('id')->from('leads')->where('assigned_to', $me)));

        $counts = [];
        $take = fn ($q) => [(clone $q)->limit(5)->get(), (clone $q)->reorder()->count()];
        [$toAccept, $counts['toAccept']] = $take(Lead::query()->where('assigned_to', $me)->where('assignment_status', 'pending')->open()->latest());
        [$followUps, $counts['followUps']] = $take(Lead::query()->where('assigned_to', $me)->open()->followUpDue()->orderBy('next_follow_up_at'));
        [$drafts, $counts['drafts']] = $take($mine(Quotation::query())->where('status', QuotationStatus::Draft)->with(['customer', 'lead', 'bookable'])->latest());
        [$waiting, $counts['waiting']] = $take($mine(Quotation::query())->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation])->with(['customer', 'lead', 'bookable'])->orderBy('valid_until'));
        [$accepted, $counts['accepted']] = $take($mine(Quotation::query())->where('status', QuotationStatus::Accepted)->whereNull('booking_id')->with(['customer', 'lead', 'bookable'])->latest('accepted_at'));
        [$unpaid, $counts['unpaid']] = $take(Booking::query()->where('assigned_to', $me)->where('status', BookingStatus::PendingPayment)->with(['customer', 'bookable'])->orderBy('start_at'));
        [$soon, $counts['soon']] = $take(Booking::query()->where('assigned_to', $me)->where('status', BookingStatus::Confirmed)->whereBetween('start_at', [now(), now()->addDays(7)])->with(['customer', 'bookable'])->orderBy('start_at'));

        $steps = [
            ['key' => 'accept', 'count' => $counts['toAccept'], 'title' => 'New enquiries for you', 'hint' => 'Accept them or hand them back', 'tone' => 'danger',
                'items' => $toAccept->map(fn (Lead $l) => $lead($l, 'Given to you '.$l->updated_at?->diffForHumans()))->all(),
                'more' => LeadResource::getUrl('index', ['activeTab' => 'mine'])],
            ['key' => 'follow', 'count' => $counts['followUps'], 'title' => 'Follow-ups due', 'hint' => 'Call or message them', 'tone' => 'warning',
                'items' => $followUps->map(fn (Lead $l) => $lead($l, 'Due '.$l->next_follow_up_at?->diffForHumans()))->all(),
                'more' => LeadResource::getUrl('index', ['activeTab' => 'follow_up'])],
            ['key' => 'draft', 'count' => $counts['drafts'], 'title' => 'Quotations to send', 'hint' => 'Drafts not sent yet', 'tone' => '',
                'items' => $drafts->map(fn (Quotation $q) => $quote($q, 'AED '.number_format((float) $q->total)))->all(),
                'more' => QuotationResource::getUrl('index', ['activeTab' => 'open'])],
            ['key' => 'waiting', 'count' => $counts['waiting'], 'title' => 'Waiting for the customer', 'hint' => 'Sent quotations', 'tone' => '',
                'items' => $waiting->map(fn (Quotation $q) => $quote($q, $q->valid_until ? ($q->valid_until->lt(today()) ? 'expired '.$q->valid_until->format('d M').', send a new one' : 'valid until '.$q->valid_until->format('d M')) : 'sent'))->all(),
                'more' => QuotationResource::getUrl('index', ['activeTab' => 'open'])],
            ['key' => 'book', 'count' => $counts['accepted'], 'title' => 'Accepted: make the booking', 'hint' => 'Check documents and dates, then create it', 'tone' => 'success',
                'items' => $accepted->map(fn (Quotation $q) => $quote($q, 'accepted '.$q->accepted_at?->diffForHumans()))->all(),
                'more' => QuotationResource::getUrl('index', ['activeTab' => 'accepted'])],
            ['key' => 'pay', 'count' => $counts['unpaid'], 'title' => 'Waiting for payment', 'hint' => 'Your bookings not paid yet', 'tone' => 'warning',
                'items' => $unpaid->map(fn (Booking $b) => $booking($b, 'starts '.$b->start_at?->format('d M, H:i').' · AED '.number_format((float) $b->balance).' due'))->all(),
                'more' => BookingResource::getUrl('index', ['activeTab' => 'waiting'])],
            ['key' => 'soon', 'count' => $counts['soon'], 'title' => 'Your bookings this week', 'hint' => 'Confirmed, starting in 7 days', 'tone' => '',
                'items' => $soon->map(fn (Booking $b) => $booking($b, $b->start_at?->format('D d M, H:i').((float) $b->balance > 0 ? ' · AED '.number_format((float) $b->balance).' due' : '')))->all(),
                'more' => BookingResource::getUrl('index', ['activeTab' => 'upcoming'])],
        ];

        $open = array_values(array_filter($steps, fn ($s) => $s['items'] !== []));

        return [
            'steps' => $open,
            'done' => array_values(array_map(fn ($s) => $s['title'], array_filter($steps, fn ($s) => $s['items'] === []))),
            'actions' => [
                ['label' => 'New enquiry', 'url' => LeadResource::getUrl('create'), 'icon' => 'heroicon-m-plus'],
                ['label' => 'New quotation', 'url' => QuotationResource::getUrl('create'), 'icon' => 'heroicon-m-document-text'],
                ['label' => 'Check a price', 'url' => \App\Filament\Pages\PriceCheck::getUrl(), 'icon' => 'heroicon-m-calculator'],
            ],
        ];
    }
}
