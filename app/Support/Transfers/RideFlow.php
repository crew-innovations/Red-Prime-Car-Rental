<?php

namespace App\Support\Transfers;

use App\Enums\BookingStatus;
use App\Enums\ServiceType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\LeadActivity;
use App\Models\Setting;
use App\Models\TransferService;
use App\Models\User;
use App\Notifications\RidePriceConfirmed;
use App\Support\Charges\ChargeLines;
use App\Support\Notifications\Notifier;
use App\Support\Payments\Payments;
use App\Support\Site\WebsiteBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 11 Nov (Bilal: "fully depend on distance"): the limousine ride from the website to payment.
 *
 *   customer: car class → pickup → destination → distance and estimate → Book
 *   agent:    accepts the request, adds extras if any, confirms the price   (confirm())
 *   customer: sees the price on their ride page, accepts and pays            (payUrl())
 *   agent:    assigns the driver; the same ride page then shows the car live
 *
 * No service to choose on the website: every ride is priced by road distance with one
 * service (distanceService()). Hourly and the other services stay in the CRM for staff,
 * switched off on the website by the setting limo_distance_only.
 */
class RideFlow
{
    public static function distanceOnly(): bool
    {
        return (bool) Setting::read('limo_distance_only', true);
    }

    /** The website price is an estimate until an agent confirms it. */
    public static function agentConfirms(): bool
    {
        return (bool) Setting::read('limo_agent_confirms', true);
    }

    /** The one service behind a distance ride: "point-to-point" if it exists, else the first one-way service with a destination. */
    public static function distanceService(): ?TransferService
    {
        $q = fn () => TransferService::query()->where('is_active', true)->where('is_published', true);

        return $q()->where('slug', 'point-to-point')->first()
            ?? $q()->where('journey_type', 'one_way')->where('needs_destination', true)->where('is_airport', false)->orderBy('sort_order')->first()
            ?? $q()->where('journey_type', 'one_way')->where('needs_destination', true)->orderBy('sort_order')->first();
    }

    /** A website ride request the agent has not confirmed yet. */
    public static function isRequest(Booking $b): bool
    {
        return $b->service_type === ServiceType::Transfer && $b->status === BookingStatus::Inquiry;
    }

    /**
     * The agent confirms the price: extras (label + amount) are added as charge lines, the booking
     * starts waiting for payment and the customer is told, with the link to their ride page.
     *
     * @param  array<int, array{label: string, amount: float|int|string}>  $extras
     */
    public static function confirm(Booking $b, array $extras = [], ?string $note = null, ?User $by = null): string
    {
        if (! self::isRequest($b)) {
            throw ValidationException::withMessages(['data.extras' => 'This ride is not waiting for a price confirmation.']);
        }
        if ((float) $b->total <= 0) {
            throw ValidationException::withMessages(['data.extras' => 'This ride has no price yet. Set the distance or the price on the booking first.']);
        }
        if (method_exists($b, 'priceWaiting') && $b->priceWaiting()) {
            throw ValidationException::withMessages(['data.extras' => 'The price is waiting for a manager\'s approval.']);
        }

        DB::transaction(function () use ($b, $extras, $note, $by) {
            foreach ($extras as $x) {
                $amount = round((float) ($x['amount'] ?? 0), 2);
                if ($amount > 0 && filled($x['label'] ?? null)) {
                    ChargeLines::add($b, 'other', trim((string) $x['label']), $amount);
                }
            }
            $b->refresh();
            $hours = WebsiteBooking::holdHours();
            $b->status = BookingStatus::PendingPayment;
            $b->hold_until = $hours > 0 ? now()->addHours($hours) : null;
            $b->save();
            $b->syncBalance();
            $total = 'AED '.number_format((float) $b->fresh()->balance, 2);
            AuditLog::note($b, 'ride_price_confirmed', 'Price confirmed and sent to the customer: '.$total.($note ? ' · '.$note : ''));
            if ($b->lead_id) {
                LeadActivity::create(['lead_id' => $b->lead_id, 'user_id' => $by?->id ?? auth()->id(), 'type' => 'note', 'body' => 'Price confirmed for '.$b->reference.': '.$total.'. Sent to the customer.']);
            }
        });

        $b = $b->fresh();
        $link = Tracking::link($b);
        // A customer with a login is told on that address; others on the email of the customer record.
        $user = User::where('customer_id', $b->customer_id)->first();
        $email = $b->customer?->email;
        if ($user || filled($email)) {
            rescue(fn () => $user ? Notifier::send($user, new RidePriceConfirmed($b, $link, $note), $b) : Notifier::mail($email, new RidePriceConfirmed($b, $link, $note), $b), report: false);
        }

        return $link;
    }

    /** What the customer still has to pay now: the advance if one is set and not reached, else the balance. */
    public static function due(Booking $b): float
    {
        if ((float) $b->advance_required > 0 && (float) $b->paid_amount + 0.004 < (float) $b->advance_required) {
            return round((float) $b->advance_required - (float) $b->paid_amount, 2);
        }

        return max(0, round((float) $b->balance, 2));
    }

    public static function canPayOnline(Booking $b): bool
    {
        return $b->status === BookingStatus::PendingPayment && self::due($b) > 0.004 && (bool) Payments::connected();
    }

    /** The customer accepts the price: the payment page for the advance (or the whole balance). */
    public static function payUrl(Booking $b): string
    {
        if (! self::canPayOnline($b)) {
            throw ValidationException::withMessages(['pay' => 'Online payment is not available for this ride right now. Please contact us.']);
        }
        $advance = (float) $b->advance_required > 0 && (float) $b->paid_amount + 0.004 < (float) $b->advance_required;
        // A link that is still open is used again (pressing the button twice must not make a second one).
        $r = \App\Models\PaymentRequest::query()->where('booking_id', $b->id)->whereIn('purpose', ['advance', 'balance'])->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first()
            ?? ($advance ? Payments::advanceLink($b) : Payments::balanceLink($b));
        if (! AuditLog::query()->where('auditable_type', $b->getMorphClass())->where('auditable_id', $b->id)->where('event', 'ride_price_accepted')->exists()) {
            AuditLog::note($b, 'ride_price_accepted', 'The customer accepted the price and opened the payment page.');
            if ($b->lead_id) {
                rescue(fn () => LeadActivity::create(['lead_id' => $b->lead_id, 'type' => 'note', 'body' => 'The customer accepted the price for '.$b->reference.' and opened the payment page.']), report: false);
            }
        }

        return $r->url();
    }
}
