<?php

namespace App\Support\Sales;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Quotation;

/**
 * The sales-to-rental journey as separate steps (28 Oct, 3.4): quotation
 * accepted, customer profile, documents, booking, confirmation, payment,
 * rental, completion. Worked out from the records; nothing extra is stored,
 * and quotation, booking, payment and document statuses stay separate.
 * Each step: ['label', 'state' => done|now|todo|skip, 'text'].
 */
class Journey
{
    public static function forQuotation(Quotation $q): array
    {
        return self::build($q, $q->booking);
    }

    public static function forBooking(Booking $b): array
    {
        return self::build($b->quotation, $b);
    }

    protected static function build(?Quotation $q, ?Booking $b): array
    {
        $steps = [];
        $customerId = $b?->customer_id ?? $q?->customer_id;

        if ($q) {
            $steps[] = ['Quotation sent', $q->sent_at ? 'done' : 'now', $q->sent_at ? $q->sent_at->format('d M, H:i').($q->viewed_at ? ' · opened' : '') : 'Send it to the customer.'];
            $steps[] = ['Quotation accepted', $q->accepted_at ? 'done' : ($q->sent_at ? 'now' : 'todo'),
                $q->accepted_at ? $q->accepted_at->format('d M, H:i').' · '.(QuotationDecision::VIA[$q->accepted_via] ?? 'recorded') : ($q->rejected_at ? 'Declined: '.$q->rejection_reason : 'Waiting for the customer.')];
        }

        $steps[] = ['Customer profile', $customerId ? 'done' : 'todo', $customerId ? ($b?->customer?->name ?? $q?->customer?->name) : 'Created when the quotation is accepted.'];

        $checklist = $b ? $b->documentChecklist() : ($q ? $q->documentChecklist() : []);
        $missing = collect($checklist)->reject(fn ($row) => $row[2]);
        $steps[] = ['Documents', ! $customerId ? 'todo' : ($missing->isEmpty() ? 'done' : 'now'),
            $checklist === [] ? 'None required.' : ($missing->isEmpty() ? 'All approved.' : $missing->map(fn ($r) => $r[0].': '.strtolower($r[1]))->implode(', '))];

        $status = $b?->status;
        $steps[] = ['Booking', $b ? 'done' : ($q?->accepted_at ? 'now' : 'todo'), $b ? $b->reference.' · '.$status?->getLabel() : 'Create it from the accepted quotation.'];

        $cancelled = in_array($status, [BookingStatus::Cancelled, BookingStatus::NoShow], true);
        $confirmed = in_array($status, [BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed], true);
        $steps[] = ['Booking confirmed', $cancelled ? 'skip' : ($confirmed ? 'done' : ($b ? 'now' : 'todo')), $cancelled ? $status->getLabel() : ($confirmed ? 'Confirmed' : 'Not confirmed yet.')];

        $pay = $b?->payment_status;
        $steps[] = ['Payment', $cancelled ? 'skip' : ($pay === PaymentStatus::Paid ? 'done' : ($b && $confirmed ? 'now' : 'todo')),
            $b ? ($pay ? $pay->getLabel().' · AED '.number_format((float) $b->paid_amount, 2).' received, AED '.number_format((float) $b->balance, 2).' to pay' : 'Nothing to pay yet.') : '—'];

        $noun = $b && ! $b->isCar() ? $b->serviceNoun() : 'rental';
        $steps[] = [ucfirst($noun).' in progress', $cancelled ? 'skip' : ($b?->delivered_at ? 'done' : ($confirmed ? 'todo' : 'todo')), $b?->delivered_at ? 'Since '.$b->delivered_at->format('d M, H:i') : ($b?->start_at ? 'Starts '.$b->start_at->format('d M, H:i') : '—')];
        $steps[] = [ucfirst($noun).' completed', $cancelled ? 'skip' : ($status === BookingStatus::Completed ? 'done' : 'todo'), $b?->returned_at ? $b->returned_at->format('d M, H:i') : ($b?->end_at ? 'Ends '.$b->end_at->format('d M, H:i') : '—')];

        return $steps;
    }

    public static function next(array $steps): ?array
    {
        return collect($steps)->firstWhere(1, 'now');
    }

    public static function html(array $steps): string
    {
        $rows = collect($steps)->map(fn ($s) => '<li class="rp-step rp-step-'.$s[1].'"><span class="rp-step-dot"></span><span><strong>'.e($s[0]).'</strong><br><span class="rp-step-text">'.e((string) $s[2]).'</span></span></li>');

        return '<ol class="rp-steps">'.$rows->implode('').'</ol>';
    }
}
