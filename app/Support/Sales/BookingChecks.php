<?php

namespace App\Support\Sales;

use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\TripDate;
use App\Support\Availability;

/**
 * What to check before an accepted quotation becomes a booking (28 Oct, 3.6):
 * customer profile, availability, documents, advance and driver.
 * Each check: ['label', 'state' => ok|warn|bad|info, 'text'].
 */
class BookingChecks
{
    public static function forQuotation(Quotation $q): array
    {
        $checks = [];
        $checks[] = ['Customer profile', $q->customer_id ? 'ok' : 'bad', $q->customer_id ? $q->customer?->name : 'Missing: accept the quotation first.'];

        $type = $q->service_type?->value;
        if (in_array($type, ['car', 'yacht'], true) && $q->bookable_id && $q->start_at && $q->end_at) {
            $clash = Availability::assetConflict($type, (int) $q->bookable_id, $q->start_at, $q->end_at);
            $limit = Availability::restriction($type, (int) $q->bookable_id);
            $checks[] = ['Availability', $clash ? 'bad' : ($limit ? 'warn' : 'ok'), $clash ?: ($limit ?: 'Free for these dates.')];
        } elseif ($type === 'trip' && $q->trip_date_id && ($date = TripDate::with('trip')->find($q->trip_date_id))) {
            $left = Availability::seatsLeft($date);
            $checks[] = ['Seats', $left !== null && (int) $q->guests > $left ? 'bad' : 'ok', $left === null ? 'No seat limit.' : $left.' seat(s) left.'];
        }

        $missing = self::missingDocuments($q);
        $checks[] = ['Documents', $missing ? (Setting::read('doc_block_confirm') ? 'bad' : 'warn') : 'ok',
            $missing ? 'Missing or not approved yet: '.implode(', ', $missing).'.' : 'All required documents approved.'];

        if ((float) $q->advance_required > 0) {
            $checks[] = ['Advance', 'info', 'AED '.number_format((float) $q->advance_required, 2).' to confirm, as quoted.'];
        }
        if ($q->security_deposit > 0) {
            $checks[] = ['Security deposit', 'info', 'AED '.number_format((float) $q->security_deposit, 2).' at handover (refundable, not revenue).'];
        }
        if ($q->service_type === ServiceType::Car && ($q->drive_mode === DriveMode::WithDriver || $q->delivery_method === 'delivery' || $q->return_method === 'collection')) {
            $checks[] = ['Driver', 'info', 'Pick the driver on the booking. Busy drivers are marked.'];
        }
        if ($q->isAdjusted()) {
            $checks[] = ['Price', 'info', 'Agreed AED '.number_format((float) $q->total, 2).' (price list AED '.number_format((float) $q->standard_amount, 2).'): '.$q->adjustment_reason];
        }

        return $checks;
    }

    /** @return array<int, string> labels of documents still needed */
    public static function missingDocuments(Quotation $q): array
    {
        return collect($q->documentChecklist())->reject(fn ($row) => $row[2])->map(fn ($row) => $row[0])->values()->all();
    }

    /** Why "Confirmed" is not allowed yet, or null. */
    public static function confirmBlocked(Quotation $q): ?string
    {
        if (Setting::read('doc_block_confirm') && ($missing = self::missingDocuments($q))) {
            return 'Can be confirmed once these documents are approved: '.implode(', ', $missing).'. Create it as Waiting for payment for now.';
        }

        return null;
    }

    public static function html(array $checks): string
    {
        $icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✕', 'info' => 'i'];
        $rows = collect($checks)->map(fn ($c) => '<li class="rp-check rp-check-'.$c[1].'"><span class="rp-check-icon">'.$icon[$c[1]].'</span><span><strong>'.e($c[0]).'</strong><br>'.e((string) $c[2]).'</span></li>');

        return '<ul class="rp-checks">'.$rows->implode('').'</ul>';
    }
}
