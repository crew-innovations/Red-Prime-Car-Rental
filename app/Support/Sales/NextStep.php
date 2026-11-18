<?php

namespace App\Support\Sales;

use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Quotation;
use App\Support\Deposits\Settlement;
use Illuminate\Database\Eloquent\Model;

/**
 * 6 Nov ("Make it simple", S4 / C.2): where a sale stands and what to do next.
 *
 * Reads what is already stored (lead stage, quotation status, booking status, money, handover and
 * return times, deposit); it stores nothing. The page shows the progress bar and makes the action
 * named in 'next' its one big button; the actions themselves are the existing ones.
 */
class NextStep
{
    public const STEPS = ['lead' => 'Lead', 'quote' => 'Quote', 'booked' => 'Booked', 'paid' => 'Paid', 'delivered' => 'Delivered', 'returned' => 'Returned', 'closed' => 'Closed'];

    /** Which step each next-step action moves on. */
    public const STEP_OF = ['claimLead' => 'lead', 'acceptLead' => 'lead', 'quote' => 'quote', 'limoPrice' => 'quote', 'confirmRide' => 'quote', 'send' => 'quote', 'accept' => 'quote', 'approvePrice' => 'quote',
        'convert' => 'booked', 'payment' => 'paid', 'approveHandover' => 'delivered', 'deliver' => 'delivered', 'receive' => 'returned', 'settleDeposit' => 'closed'];

    /** @return array{steps: list<array{key: string, label: string, done: bool, current: bool}>, next: ?string, stopped: ?string} */
    public static function for(Model $record): array
    {
        [$lead, $quote, $booking] = self::chain($record);
        $stopped = self::stopped($lead, $quote, $booking);

        $done = [
            'lead' => (bool) ($lead || $quote?->lead_id || $booking?->lead_id || $quote || $booking),
            'quote' => (bool) ($quote && $quote->status !== QuotationStatus::Draft) || (bool) $booking,
            'booked' => $booking && in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed], true),
            'paid' => $booking && ($booking->payment_status === PaymentStatus::Paid || ((float) $booking->total > 0 && (float) $booking->balance <= 0.004)),
            'delivered' => (bool) $booking?->delivered_at,
            'returned' => (bool) $booking?->returned_at,
            'closed' => $booking && $booking->status === BookingStatus::Completed && (float) $booking->balance <= 0.004 && ! self::depositHeld($booking),
        ];
        // The highlighted step is the one the next button moves on (a car can go out before it is
        // fully paid); without a button, the first step not done yet.
        $next = $stopped ? null : self::next($record, $lead, $quote, $booking);
        $current = $stopped ? null : (self::STEP_OF[$next] ?? collect(array_keys(self::STEPS))->first(fn ($k) => ! $done[$k]));

        $steps = [];
        foreach (self::STEPS as $key => $label) {
            $steps[] = ['key' => $key, 'label' => $label, 'done' => $done[$key], 'current' => $key === $current];
        }

        return ['steps' => $steps, 'next' => $next, 'stopped' => $stopped];
    }

    /** @return array{0: ?Lead, 1: ?Quotation, 2: ?Booking} */
    protected static function chain(Model $record): array
    {
        if ($record instanceof Booking) {
            return [$record->lead, $record->quotation, $record];
        }
        if ($record instanceof Quotation) {
            $booking = $record->booking ?? Booking::query()->where('quotation_id', $record->id)->latest('id')->first();

            return [$record->lead, $record, $booking];
        }
        if ($record instanceof Lead) {
            $booking = $record->bookings()->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])->latest('id')->first();
            $quote = $booking?->quotation ?? $record->quotations()->latest('id')->first();

            return [$record, $quote, $booking];
        }

        return [null, null, null];
    }

    protected static function stopped(?Lead $lead, ?Quotation $quote, ?Booking $booking): ?string
    {
        if ($booking && in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            return $booking->status->getLabel();
        }
        if (! $booking && $quote && in_array($quote->status, [QuotationStatus::Rejected, QuotationStatus::Expired], true)) {
            return 'Quote '.strtolower($quote->status->getLabel());
        }
        if (! $booking && $lead && $lead->status === LeadStatus::Lost) {
            return 'Lost';
        }

        return null;
    }

    public static function depositHeld(Booking $b): bool
    {
        return Settlement::dueQuery()->whereKey($b->id)->exists()
            || ($b->status === BookingStatus::Completed && $b->deposit_refundable_on && $b->deposit_refundable_on->isFuture() && (float) $b->security_deposit > 0);
    }

    /** The name of the existing page action that is the next step on this page, if any. */
    protected static function next(Model $record, ?Lead $lead, ?Quotation $quote, ?Booking $booking): ?string
    {
        if ($record instanceof Lead) {
            if (! $record->assigned_to) {
                return $record->broadcast_at ? 'claimLead' : 'acceptLead';
            }
            if ($booking) {
                return null; // the booking page carries on from here
            }

            if ($quote) {
                return null;
            }

            return $record->service_type === \App\Enums\ServiceType::Transfer ? 'limoPrice' : 'quote'; // 6 Nov: limousine leads get the quick price
        }

        if ($record instanceof Quotation) {
            if (method_exists($record, 'priceWaiting') && $record->priceWaiting()) {
                return 'approvePrice';
            }

            return match ($record->status) {
                QuotationStatus::Draft => 'send',
                QuotationStatus::Sent, QuotationStatus::Negotiation => 'accept',
                QuotationStatus::Accepted => $booking ? null : 'convert',
                default => null,
            };
        }

        /** @var Booking $record */
        if (method_exists($record, 'priceWaiting') && $record->priceWaiting()) {
            return 'approvePrice';
        }

        if (\App\Support\Transfers\RideFlow::isRequest($record)) {
            return 'confirmRide'; // 11 Nov: a website ride waits for the agent's price first
        }

        return match ($record->status) {
            BookingStatus::Inquiry, BookingStatus::Quotation, BookingStatus::PendingPayment => 'payment',
            BookingStatus::Confirmed => $record->delivered_at ? null
                : ($record->jobs()->where('type', 'delivery')->where('status', 'waiting')->exists() ? 'approveHandover' : 'deliver'),
            BookingStatus::InProgress => 'receive',
            BookingStatus::Completed => (float) $record->balance > 0.004 ? 'payment' : (Settlement::dueQuery()->whereKey($record->id)->exists() ? 'settleDeposit' : null),
            default => null,
        };
    }
}
