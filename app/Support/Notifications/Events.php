<?php

namespace App\Support\Notifications;

/**
 * Every event people are told about (4 Nov, brief 9.3). The key goes on each notification,
 * picks the person's channel preferences and makes "one event, one notification" possible.
 * Urgent events ignore quiet hours.
 */
class Events
{
    public const ALL = [
        'lead_new' => ['New lead', 'Sales'],
        // 5 Nov (S3): every agent can accept a new lead; first one owns it
        'lead_broadcast' => ['New lead to accept', 'Sales'],
        'lead_claimed' => ['Lead accepted by an agent', 'Sales'],
        'lead_reassigned' => ['Lead / customer moved to another agent', 'Sales'],
        'cancel_requested' => ['Booking cancelled / cancellation to approve', 'Bookings'],
        'lead_unaccepted' => ['Lead not accepted in time', 'Sales'],
        'lead_returned' => ['Lead handed back / asked again', 'Sales'],
        'quote_accepted' => ['Quotation accepted', 'Sales'],
        'quote_rejected' => ['Quotation declined', 'Sales'],
        'quote_expiring' => ['Quotation expiring in 24 hours', 'Sales'],
        'price_approval' => ['Price approval', 'Sales'],
        'follow_up' => ['Customer has not answered (automatic follow-up)', 'Sales'], // 12 Nov
        'website_booking' => ['Website "Book now" booking', 'Bookings'],
        'booking_confirmed' => ['Booking confirmed', 'Bookings'],
        'payment_received' => ['Payment received', 'Money'],
        'payment_check' => ['Payment needs checking', 'Money'],
        'advance_reached' => ['Advance paid', 'Money'],
        'balance_due' => ['Balance due soon / overdue', 'Money'],
        'deposit_refunded' => ['Deposit refunded', 'Money'],
        'fine_charged' => ['Traffic fine / tolls charged', 'Money'],
        'fine_review' => ['Fines and tolls to review', 'Money'],
        'deposit_settle' => ['Deposits to settle', 'Money'],
        'fuel_to_confirm' => ['Fuel fill-up to confirm', 'Money'],      // 5 Nov (S5)
        'fuel_prices' => ['Monthly fuel prices to update', 'Money'],
        'job_changed' => ['Driver job assigned / changed / cancelled', 'Drivers'],
        'job_soon' => ['Job starts soon', 'Drivers'],
        'job_declined' => ['Driver declined a job / job not accepted', 'Drivers'],
        'driver_late' => ['Driver running late', 'Drivers'],
        'driver_update' => ['Driver on the way / running late (customer)', 'Bookings'],
        'handed_over' => ['Car handed over / charter started', 'Operations'],
        'returned' => ['Car returned / charter finished', 'Operations'],
        'late_return' => ['Late return', 'Operations'],
        'damage' => ['Damage reported', 'Operations'],
        'document_uploaded' => ['Customer document uploaded', 'Documents'],
        'document_rejected' => ['Document rejected / expiring', 'Documents'],
        'document_approved' => ['Customer documents approved (account ready)', 'Documents'],
        'message' => ['New message', 'Messages'],
        'other' => ['Other CRM notices', 'Other'],
    ];

    /** Never held back by quiet hours. */
    public const URGENT = ['job_changed', 'job_soon', 'payment_check', 'job_declined', 'driver_late'];

    public const CHANNELS = ['database' => 'In the app (bell)', 'push' => 'Phone / desktop push', 'mail' => 'Email', 'whatsapp' => 'WhatsApp'];

    public static function label(string $event): string
    {
        return self::ALL[$event][0] ?? self::ALL['other'][0];
    }

    public static function valid(?string $event): string
    {
        return $event && array_key_exists($event, self::ALL) ? $event : 'other';
    }
}
