<?php

namespace App\Support\Notifications;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\QuotationResource;
use App\Models\Booking;
use App\Models\CustomerDocument;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Support\Drivers\Notify;

/**
 * The events of brief 9.3 that are not already sent where the work happens. Called from the
 * models and services (never from Filament pages), so website, API and staff actions all
 * notify the same way. Every one has a dedupe key: one event, one notification per person.
 */
class EventHooks
{
    protected static function money(float $v): string
    {
        return 'AED '.number_format($v, 2);
    }

    protected static function bookingUrl(Booking $b): ?string
    {
        return rescue(fn () => BookingResource::getUrl('edit', ['record' => $b], panel: 'admin'), null, false);
    }

    protected static function accountUrl(Booking $b): string
    {
        return url('/account/bookings/'.$b->id);
    }

    /** Status changes of a booking (from Booking::saved). */
    public static function bookingStatus(Booking $b): void
    {
        if (! $b->wasChanged('status') && ! $b->wasRecentlyCreated) {
            return;
        }
        $key = 'booking:'.$b->id;
        $what = $b->title();
        match ($b->status) {
            BookingStatus::Confirmed => self::confirmed($b, $key, $what),
            BookingStatus::InProgress => Notify::customer($b->customer, $b->isCar() ? 'Your car has been delivered' : 'Your '.$b->serviceNoun().' has started',
                $what.'. Enjoy! Questions? Message us from your account.', self::accountUrl($b), 'handed_over', $b, 'handed_over:'.$key),
            BookingStatus::Completed => self::completed($b, $key, $what),
            default => null,
        };
    }

    protected static function confirmed(Booking $b, string $key, string $what): void
    {
        if ($b->wasRecentlyCreated && $b->source !== 'website' && ! $b->quotation_id && auth()->check()) {
            // Booked straight away by staff: the customer is told; staff made it themselves.
        } else {
            Notify::team('operate-fleet', 'Booking confirmed: '.$b->reference, $what.' · '.$b->start_at?->format('D d M, H:i'), self::bookingUrl($b), $b->assignee, 'success', 'booking_confirmed', 'booking_confirmed:'.$key);
        }
        Notify::customer($b->customer, 'Your booking '.$b->reference.' is confirmed',
            $what.', '.$b->start_at?->format('D d M Y, H:i').'.'.((float) $b->balance > 0 ? ' Balance to pay: '.self::money((float) $b->balance).'.' : ''),
            self::accountUrl($b), 'booking_confirmed', $b, 'booking_confirmed:'.$key, 'success');
    }

    protected static function completed(Booking $b, string $key, string $what): void
    {
        Notify::team('manage-finance', ($b->isCar() ? 'Car returned: ' : 'Finished: ').$b->reference,
            $what.((float) $b->balance > 0 ? ' · balance '.self::money((float) $b->balance) : ' · settled').($b->depositHeld() > 0 ? ' · deposit held '.self::money($b->depositHeld()) : ''),
            self::bookingUrl($b), $b->assignee, 'info', 'returned', 'returned:'.$key);
        $statement = $b->delivered_at ? route('pdf.statement', $b) : null;
        Notify::customer($b->customer, 'Thank you for choosing us',
            $what.' is complete.'.($statement ? ' Your statement is in your account.' : '').((float) $b->balance > 0 ? ' Balance to pay: '.self::money((float) $b->balance).'.' : ''),
            $statement ?? self::accountUrl($b), 'returned', $b, 'returned:'.$key, 'success');
    }

    /** The advance is paid (Booking::syncBalance, 4 Nov 11.4). */
    public static function advanceReached(Booking $b): void
    {
        $key = 'advance_reached:booking:'.$b->id;
        Notify::team('manage-finance', 'Advance paid: '.$b->reference, self::money((float) $b->paid_amount).' received of '.self::money((float) $b->total).'.',
            self::bookingUrl($b), $b->assignee, 'success', 'advance_reached', $key);
        Notify::customer($b->customer, 'We received your advance for '.$b->reference,
            self::money((float) $b->paid_amount).' received.'.((float) $b->balance > 0 && ($due = \App\Support\Pricing\Advance::balanceDue($b)) ? ' Balance '.self::money((float) $b->balance).' due '.$due->format('d M Y').'.' : ''),
            self::accountUrl($b), 'advance_reached', $b, $key, 'success');
    }

    /** Money in (from Transaction::saved): a verified / recorded payment, or a deposit refund. */
    public static function transaction(Transaction $t): void
    {
        if ($t->reversed_at || $t->status !== PaymentStatus::Paid || $t->is_balance || ! $t->booking_id) {
            return;
        }
        if (! $t->wasRecentlyCreated && ! $t->wasChanged('is_balance')) {
            return;
        }
        $b = $t->booking;
        if (! $b) {
            return;
        }
        if ($t->type === TransactionType::Income && $t->category !== 'deposit') {
            $key = 'payment:transaction:'.$t->id;
            Notify::team('manage-finance', 'Payment received: '.self::money((float) $t->amount_paid ?: (float) $t->amount).' · '.$b->reference,
                ($t->method?->getLabel() ?? 'Payment').($b->balance > 0 ? ' · balance '.self::money((float) $b->balance) : ' · fully paid'),
                self::bookingUrl($b), $b->assignee, 'success', 'payment_received', $key);
            Notify::customer($b->customer, 'Payment received: '.self::money((float) $t->amount).' for '.$b->reference,
                'Thank you. Your receipt is ready.'.((float) $b->fresh()->balance > 0 ? ' Balance: '.self::money((float) $b->fresh()->balance).'.' : ''),
                route('pdf.receipt', $t), 'payment_received', $t, $key, 'success');
        } elseif ($t->type === TransactionType::Expense && $t->category === 'deposit_refund' && ! str_contains((string) $t->description, 'applied to balance')) {
            $key = 'deposit_refunded:transaction:'.$t->id;
            Notify::team('manage-finance', 'Deposit refunded: '.self::money((float) $t->amount).' · '.$b->reference, $t->method?->getLabel(),
                self::bookingUrl($b), null, 'info', 'deposit_refunded', $key);
            Notify::customer($b->customer, 'Your deposit refund for '.$b->reference, self::money((float) $t->amount).' has been refunded.',
                self::accountUrl($b), 'deposit_refunded', $t, $key, 'success');
        }
    }

    /** A quotation accepted by the customer (QuotationDecision::accept). */
    public static function quoteAccepted(Quotation $q, ?string $body): void
    {
        $key = 'quote_accepted:quotation:'.$q->id;
        Notify::team('assign-leads', 'Quotation '.$q->number.' accepted by '.($q->customerName() ?? 'the customer'), $body,
            QuotationResource::getUrl('edit', ['record' => $q], panel: 'admin'), $q->lead?->assignee ?? $q->creator, 'success', 'quote_accepted', $key);
        $customer = $q->customer;
        $text = 'Thank you for accepting quotation '.$q->number.'. Our team will confirm your booking shortly.'
            .((float) $q->advance_required > 0 ? ' To confirm it, pay the advance of '.self::money((float) $q->advance_required).'.' : '');
        if ($customer) {
            Notify::customer($customer, 'We received your acceptance of '.$q->number, $text, $q->publicUrl(), 'quote_accepted', $q, $key, 'success');
        } elseif (filled($q->customerEmail())) {
            Notifier::mail($q->customerEmail(), new \App\Notifications\CrmMessage('We received your acceptance of '.$q->number, $text, $q->publicUrl(), false, 'heroicon-o-bell', 'success', 'quote_accepted'), $q, $key);
        }
    }

    public static function quoteRejected(Quotation $q, string $reason): void
    {
        Notify::team('assign-leads', 'Quotation '.$q->number.' declined', $reason,
            QuotationResource::getUrl('edit', ['record' => $q], panel: 'admin'), $q->lead?->assignee ?? $q->creator, 'danger', 'quote_rejected', 'quote_rejected:quotation:'.$q->id);
    }

    /** A customer document was rejected / a new upload asked for (CustomerDocument::saved). */
    public static function document(CustomerDocument $d): void
    {
        // 19 Nov: the last required document approved → the customer's account opens; tell them.
        if ($d->wasChanged('status') && $d->status === DocumentStatus::Approved && $d->customer
            && \App\Support\CustomerArea::documentsGateOn()) {
            $list = \App\Support\CustomerArea::requiredDocumentsFor($d->customer);
            if ($list !== [] && collect($list)->every(fn (array $row) => $row[2]) && \App\Support\CustomerArea::profileStillNeededFor($d->customer) === []) {
                Notify::customer($d->customer, 'Your documents are approved', 'Thank you. Your account is ready: you can see your bookings, payments and messages.',
                    url('/account'), 'document_approved', $d, 'documents_complete:customer:'.$d->customer_id.':'.$d->id, 'success');
            }

            return;
        }
        if (! $d->wasChanged('status') || ! in_array($d->status, [DocumentStatus::Rejected, DocumentStatus::Replacement], true)) {
            return;
        }
        $label = \App\Support\Options::documentLabel($d->type);
        Notify::customer($d->customer, 'Please send your '.$label.' again', $d->review_note ?: 'We could not accept the file you sent.',
            url('/account/documents'), 'document_rejected', $d, 'document_rejected:document:'.$d->id.':'.$d->updated_at?->timestamp, 'warning');
    }
}
