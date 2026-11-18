<?php

namespace App\Support\FollowUps;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\QuotationResource;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LeadActivity;
use App\Models\PaymentRequest;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Drivers\Notify;
use App\Support\Notifications\Notifier;
use App\Support\Transfers\Tracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 12 Nov (Bilal: "agar kuch time ma answer ni karta to follow up jana chahiay"): when something
 * was sent to a customer and they have not answered, the CRM follows up by itself.
 *
 *   quotation sent                      → not accepted / declined
 *   limousine price confirmed           → not accepted and paid
 *   website booking waiting for payment → not paid
 *   payment link                        → not paid
 *
 * At each step (Settings: hours after sending, default 2 / 24 / 72) the customer gets a short
 * reminder with the same link (their account bell or email; never between the quiet hours) and
 * the agent is told, with the page to open. Each step goes once (notification_log), it stops as
 * soon as the customer answers, and every reminder is written on the lead timeline.
 * Nothing is sent for things older than the last step + 2 days (so switching this on never
 * mails old quotations).
 */
class FollowUps
{
    public const DEFAULT_HOURS = [2, 24, 72];

    public static function enabled(): bool
    {
        return (bool) Setting::read('followup_enabled', true);
    }

    /** Hours after sending, ascending. @return list<int> */
    public static function hours(): array
    {
        $raw = Setting::read('followup_hours');
        $list = collect(is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw))->map(fn ($h) => (int) $h)->filter(fn ($h) => $h > 0 && $h <= 24 * 30)->unique()->sort()->values()->take(5)->all();

        return $list ?: self::DEFAULT_HOURS;
    }

    /** Customers are not written to at night (Dubai time). Equal hours = no quiet time. */
    public static function quiet(?Carbon $at = null): bool
    {
        $from = (int) Setting::read('followup_quiet_from', 22);
        $to = (int) Setting::read('followup_quiet_to', 8);
        $h = (int) ($at ?? now())->format('G');

        return $from === $to ? false : ($from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to));
    }

    /** Runs every few minutes (crm:notification-reminders). Returns how many reminders went out. */
    public static function run(): int
    {
        if (! self::enabled() || self::quiet()) {
            return 0;
        }
        $hours = self::hours();
        $oldest = now()->subHours(end($hours) + 48);
        $n = 0;

        // 1. Quotations sent and not answered.
        Quotation::query()->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Negotiation])->whereNotNull('sent_at')
            ->where('sent_at', '>=', $oldest)->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->with(['customer', 'lead.assignee', 'creator'])->limit(300)->get()
            ->each(function (Quotation $q) use ($hours, &$n) {
                $n += (int) self::remind($q, 'quotation', $q->sent_at, $hours, $q->customer, $q->lead?->assignee ?? $q->creator, $q->lead_id,
                    'your quotation '.$q->number, $q->publicUrl(), 'Quotation '.$q->number, QuotationResource::getUrl('edit', ['record' => $q], panel: 'admin'), 'View and accept it online.');
            });

        // 2. Website bookings and limousine rides waiting for payment, nothing paid yet.
        Booking::query()->where('status', BookingStatus::PendingPayment)->where('source', 'website')->where('paid_amount', '<=', 0)
            ->where('updated_at', '>=', $oldest->copy()->subDays(2))->with(['customer', 'assignee', 'lead.assignee'])->limit(300)->get()
            ->each(function (Booking $b) use ($hours, $oldest, &$n) {
                $ride = $b->service_type === ServiceType::Transfer;
                $since = $ride ? AuditLog::query()->where('auditable_type', $b->getMorphClass())->where('auditable_id', $b->id)->where('event', 'ride_price_confirmed')->latest('id')->value('created_at') : null;
                $since = $since ? Carbon::parse($since) : $b->created_at;
                if (! $since || $since->lt($oldest)) {
                    return;
                }
                $n += (int) self::remind($b, 'booking', $since, $hours, $b->customer, $b->lead?->assignee ?? $b->assignee, $b->lead_id,
                    ($ride ? 'your ride ' : 'your booking ').$b->reference, $ride ? Tracking::link($b) : url('/account/bookings/'.$b->id), ($ride ? 'Ride ' : 'Booking ').$b->reference,
                    BookingResource::getUrl('edit', ['record' => $b], panel: 'admin'), $ride ? 'The price is confirmed: accept and pay on your ride page.' : 'It is held for you until it is paid.');
            });

        // 3. Payment links not paid (not the ones of a booking already followed up above).
        PaymentRequest::query()->whereIn('status', ['open', 'failed'])->where('created_at', '>=', $oldest)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['booking.customer', 'booking.assignee', 'booking.lead.assignee', 'quotation.customer', 'quotation.lead.assignee', 'customer', 'creator'])->limit(300)->get()
            ->each(function (PaymentRequest $r) use ($hours, &$n) {
                $b = $r->booking;
                if ($b && $b->status === BookingStatus::PendingPayment && $b->source === 'website' && (float) $b->paid_amount <= 0) {
                    return;
                }
                $customer = $r->customer ?? $b?->customer ?? $r->quotation?->customer;
                $agent = $b?->lead?->assignee ?? $r->quotation?->lead?->assignee ?? $b?->assignee ?? $r->creator;
                $n += (int) self::remind($r, 'payment', $r->created_at, $hours, $customer, $agent, $b?->lead_id ?? $r->quotation?->lead_id,
                    'your payment of AED '.number_format((float) $r->amount, 2), $r->url(), 'Payment link '.$r->number.' (AED '.number_format((float) $r->amount, 2).')',
                    $b ? BookingResource::getUrl('edit', ['record' => $b], panel: 'admin') : null, 'You can pay online in a minute.');
            });

        return $n;
    }

    /** Sends the step that is due now, once. */
    protected static function remind(Model $subject, string $kind, ?Carbon $since, array $hours, ?Customer $customer, ?User $agent, ?int $leadId,
        string $what, string $link, string $staffTitle, ?string $staffUrl, string $hint): bool
    {
        if (! $since) {
            return false;
        }
        $step = collect($hours)->filter(fn ($h) => $since->copy()->addHours($h)->lte(now()))->count();
        if ($step < 1) {
            return false;
        }
        $key = 'followup:'.$kind.':'.$subject->getKey().':'.$since->timestamp.':'.$step;
        if (! Notifier::claim($key, $subject, 'follow_up')) {
            return false; // this step already went
        }
        $last = $step >= count($hours);
        $name = strtok((string) ($customer?->name ?? ''), ' ') ?: 'there';
        $title = match (true) {
            $last => 'Last reminder: '.$what.' is still waiting',
            $step === 1 => 'A quick reminder about '.$what,
            default => 'Still interested? '.ucfirst($what).' is waiting',
        };
        $reached = $customer && (filled($customer->email) || User::query()->where('customer_id', $customer->id)->where('is_active', true)->exists());
        Notify::customer($customer, $title, 'Hi '.$name.', '.$hint.' If you have any question, just reply or message us.', $link, 'follow_up', $subject, $key.':c');
        // 12 Nov (C3): a lead that came from Instagram / Messenger / WhatsApp is reminded in that chat too,
        // while Meta still allows a message there.
        $thread = \App\Support\Chat\Social::threadOf($leadId);
        if ($thread && rescue(fn () => \App\Support\Chat\Social::system($thread, 'Hi '.$name.', just a reminder that '.$what.' is waiting for you. '.$hint.' '.$link), false, false)) {
            $reached = true;
        }

        $who = $customer?->name ?? 'The customer';
        Notify::person($agent, 'No answer yet: '.$staffTitle, $who.' has not answered after '.self::ago($hours[$step - 1]).'. '
            .($reached ? 'Reminder '.$step.' of '.count($hours).' was sent.' : 'No email on file and no open chat, so no reminder could be sent: contact them on WhatsApp.')
            .($last ? ' This was the last automatic reminder.' : ''), $staffUrl, $last ? 'danger' : 'warning', 'follow_up', $key.':a');

        if ($leadId) {
            rescue(fn () => LeadActivity::create(['lead_id' => $leadId, 'type' => 'note', 'body' => 'Automatic follow-up '.$step.' of '.count($hours).' for '.$staffTitle.': '
                .($reached ? 'reminder sent to the customer.' : 'no email on file and no open chat, agent told.')]), report: false);
        }

        return true;
    }

    protected static function ago(int $hours): string
    {
        return $hours % 24 === 0 ? ($hours / 24).' day'.($hours === 24 ? '' : 's') : $hours.' hour'.($hours === 1 ? '' : 's');
    }
}
