<?php

namespace App\Support\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\PaymentRequest;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Access;
use App\Support\Drivers\Notify;
use App\Support\Integrations\Registry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Online payments (brief 28 Oct-2, Phase 13).
 *  - Staff (or the customer from their account) ask for an amount → a payment link.
 *  - The customer picks a connected gateway on /pay/{token}.
 *  - Money is recorded ONLY from a verified provider answer (signed webhook, or a
 *    server-to-server status check / capture), once, for the exact amount asked.
 *  - Refunds go through the gateway, by finance only, and are recorded the same way.
 */
class Payments
{
    public const GATEWAYS = [
        'stripe' => Gateways\Stripe::class,
        'paymob' => Gateways\Paymob::class,
        'tabby' => Gateways\Tabby::class,
        'tamara' => Gateways\Tamara::class,
        'paypal' => Gateways\PayPal::class,
    ];

    /** A gateway that is switched on, has its keys and passed its connection test. */
    public static function gateway(string $key, bool $connectedOnly = true): ?Gateway
    {
        $class = self::GATEWAYS[$key] ?? null;
        if (! $class) {
            return null;
        }
        $i = Integration::query()->where('provider', $key)->first();
        if (! $i || ($connectedOnly ? ! $i->isConnected() : ! $i->isReady())) {
            return null;
        }

        return new $class($i);
    }

    /** @return array<string, Gateway> */
    public static function connected(): array
    {
        return collect(array_keys(self::GATEWAYS))->mapWithKeys(fn ($k) => [$k => self::gateway($k)])->filter()->all();
    }

    /** Gateways the customer can use for this request. */
    public static function choices(PaymentRequest $r): array
    {
        $allowed = array_filter((array) $r->providers);

        return collect(self::connected())
            ->filter(fn (Gateway $g, $k) => ! $allowed || in_array($k, $allowed, true))
            ->filter(fn (Gateway $g) => $g->refuses($r) === null)
            ->all();
    }

    /** How much can still be asked for, per purpose. */
    public static function limit(Booking|Quotation $for, string $purpose): float
    {
        if ($for instanceof Booking) {
            if ($purpose === 'security_deposit') {
                return max(0, round((float) $for->security_deposit - $for->depositHeld() - self::openTotal($for, 'security_deposit'), 2));
            }

            return max(0, round((float) $for->fresh()->balance - self::openTotal($for, ['advance', 'balance']), 2));
        }

        $paid = (float) PaymentRequest::query()->where('quotation_id', $for->id)->whereIn('status', ['paid', 'partly_refunded'])->sum(DB::raw('amount - refunded_amount'));

        return max(0, round((float) $for->total - $paid - self::openTotal($for, ['advance', 'balance']), 2));
    }

    /** Links still waiting (not counting expired ones). */
    protected static function openTotal(Booking|Quotation $for, string|array $purpose): float
    {
        return (float) PaymentRequest::query()
            ->where($for instanceof Booking ? 'booking_id' : 'quotation_id', $for->id)
            ->whereIn('purpose', (array) $purpose)->whereIn('status', ['open', 'processing'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('amount');
    }

    public static function defaultAmount(Booking|Quotation $for, string $purpose): ?float
    {
        if ($for instanceof Quotation && $purpose === 'advance') {
            $left = round((float) $for->advance_required - self::paidOnQuotation($for), 2);

            return $left > 0 ? min($left, self::limit($for, 'advance')) : null;
        }
        // 4 Nov (11): a booking's advance link asks for what is left of the advance; the balance link for the rest.
        if ($for instanceof Booking && $purpose === 'advance' && (float) $for->advance_required > 0) {
            $left = round((float) $for->advance_required - (float) $for->paid_amount, 2);

            return $left > 0 ? min($left, self::limit($for, 'advance')) : null;
        }

        return self::limit($for, $purpose) ?: null;
    }

    /** Makes (or re-uses) a payment link. Throws ValidationException with a staff-readable message. */
    public static function request(Booking|Quotation $for, string $purpose, float $amount, ?array $providers = null, ?string $description = null, ?User $by = null): PaymentRequest
    {
        $amount = round($amount, 2);
        $fail = fn (string $m) => throw ValidationException::withMessages(['data.amount' => $m]);

        if (! self::connected()) {
            $fail('No payment gateway is connected yet (Settings › Integrations).');
        }
        if (! array_key_exists($purpose, PaymentRequest::PURPOSES) || $purpose === 'other') {
            $fail('Choose what the payment is for.');
        }
        if ($amount <= 0) {
            $fail('Enter an amount above zero.');
        }
        if ($for instanceof Booking) {
            if (in_array($for->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
                $fail('This booking is '.$for->status->getLabel().'.');
            }
            if ($for->price_approval === 'pending') {
                $fail('The price is waiting for a manager\'s approval.');
            }
        } else {
            if ($for->booking_id) {
                $fail('This quotation is already a booking: ask for the payment on booking '.$for->booking?->reference.'.');
            }
            if ($for->status !== QuotationStatus::Accepted) {
                $fail('The customer must accept the quotation first.');
            }
            if ($purpose === 'security_deposit') {
                $fail('The security deposit is asked for on the booking.');
            }
        }
        $limit = self::limit($for, $purpose);
        if ($amount > $limit + 0.004) {
            $fail($limit > 0 ? 'At most AED '.number_format($limit, 2).' can be asked for now (open links count).' : 'Nothing is left to pay for this.');
        }

        return DB::transaction(function () use ($for, $purpose, $amount, $providers, $description, $by) {
            $customerId = $for instanceof Booking ? $for->customer_id : $for->customer_id;
            $days = Setting::read('payment_link_days');
            $r = PaymentRequest::create([
                'booking_id' => $for instanceof Booking ? $for->id : null,
                'quotation_id' => $for instanceof Quotation ? $for->id : $for->quotation_id,
                'customer_id' => $customerId,
                'purpose' => $purpose,
                'amount' => $amount,
                'currency' => 'AED',
                'providers' => array_values(array_intersect((array) $providers, array_keys(self::GATEWAYS))) ?: null,
                'description' => mb_substr($description ?: PaymentRequest::PURPOSES[$purpose].' · '.($for instanceof Booking ? $for->reference.' · '.($for->bookable?->name ?? $for->service_type?->getLabel()) : $for->number.' · '.$for->title()), 0, 255),
                'expires_at' => filled($days) && (int) $days > 0 ? now()->addDays((int) $days) : null,
                'created_by' => $by?->id ?? auth()->id(),
            ])->fresh();
            AuditLog::note($for, 'payment_link', 'Payment link '.$r->number.' for AED '.number_format($amount, 2).' ('.PaymentRequest::PURPOSES[$purpose].')');

            return $r;
        });
    }

    /** Customer from their account (4 Nov, 11): one link for what is left of the advance (re-used while open). */
    public static function advanceLink(Booking $b): PaymentRequest
    {
        $amount = (float) (self::defaultAmount($b, 'advance') ?? 0);
        $open = PaymentRequest::query()->where('booking_id', $b->id)->where('purpose', 'advance')->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
        if ($open) {
            if (abs((float) $open->amount - $amount) < 0.005 || $amount < 0.005) {
                return $open;
            }
            self::cancel($open, 'Replaced: the advance changed');
        }
        if ($amount < 0.005) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'The advance is already paid.']);
        }

        return self::request($b, 'advance', $amount, null, null, null);
    }

    /** Customer from their account: one link for the whole balance (re-used while it is open). */
    public static function balanceLink(Booking $b): PaymentRequest
    {
        $open = PaymentRequest::query()->where('booking_id', $b->id)->where('purpose', 'balance')->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
        if ($open) {
            if (self::limit($b, 'balance') < 0.005) {
                return $open; // that link already covers what is left
            }
            self::cancel($open, 'Replaced: the balance changed');
        }

        return self::request($b, 'balance', self::limit($b, 'balance'), null, null, null);
    }

    /** Customer from the quotation page: the advance asked for (or the full price when no advance is set). */
    public static function quotationLink(Quotation $q): PaymentRequest
    {
        $purpose = (float) $q->advance_required > 0 ? 'advance' : 'balance';
        $open = PaymentRequest::query()->where('quotation_id', $q->id)->whereNull('booking_id')->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($x) => $x->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();

        return $open ?? self::request($q, $purpose, (float) (self::defaultAmount($q, $purpose) ?? 0), null, null, null);
    }

    /** What the customer can pay now on an accepted quotation page (null = no button). */
    public static function quotationAmount(Quotation $q): ?float
    {
        if ($q->status !== QuotationStatus::Accepted || $q->booking_id || ! self::connected()) {
            return null;
        }
        $open = PaymentRequest::query()->where('quotation_id', $q->id)->whereNull('booking_id')->whereIn('status', ['open', 'processing', 'failed'])
            ->where(fn ($x) => $x->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
        if ($open) {
            return (float) $open->amount;
        }
        if (self::paidOnQuotation($q) > 0 && (float) $q->advance_required > 0 && self::paidOnQuotation($q) >= (float) $q->advance_required - 0.004) {
            return null; // the advance is paid; the rest is collected on the booking
        }

        return self::defaultAmount($q, (float) $q->advance_required > 0 ? 'advance' : 'balance') ?: null;
    }

    /** Starts a checkout with one gateway; returns the provider's checkout address. */
    public static function start(PaymentRequest $r, string $provider): string
    {
        $gateway = self::choices($r)[$provider] ?? null;
        if (! $gateway) {
            throw new GatewayError('This payment method is not available.');
        }

        $r = DB::transaction(function () use ($r) {
            $r = PaymentRequest::query()->lockForUpdate()->findOrFail($r->id);
            if (! $r->isPayable()) {
                throw new GatewayError($r->isPaid() ? 'This payment is already made.' : 'This payment link is no longer open.');
            }
            $r->forceFill(['attempts' => $r->attempts + 1])->save();

            return $r;
        });

        try {
            $out = $gateway->checkout($r, route('pay.return', ['token' => $r->token, 'provider' => $provider]), route('pay.show', $r->token).'?cancelled=1');
        } catch (\Throwable $e) {
            $r->forceFill(['last_error' => mb_substr($e instanceof GatewayError ? $e->getMessage() : class_basename($e).' while starting checkout', 0, 500)])->save();
            if (! $e instanceof GatewayError) {
                report($e);
            }
            throw $e instanceof GatewayError ? $e : new GatewayError('The payment page could not be opened. Please try again or choose another method.');
        }
        if (empty($out['url']) || empty($out['provider_ref'])) {
            throw new GatewayError('The payment page could not be opened.');
        }

        $r->forceFill([
            'provider' => $provider, 'mode' => $gateway->mode(), 'status' => 'processing', 'provider_ref' => (string) $out['provider_ref'], 'last_error' => null,
            'meta' => array_replace((array) $r->meta, (array) ($out['meta'] ?? []), ['attempt_'.$r->attempts => ['provider' => $provider, 'ref' => (string) $out['provider_ref'], 'at' => now()->toIso8601String()]]),
        ])->save();

        return (string) $out['url'];
    }

    /** Signed webhook from a provider. Returns the HTTP status to answer with. */
    public static function webhook(string $provider, Request $request): int
    {
        $gateway = self::gateway($provider, connectedOnly: false);
        if (! $gateway) {
            IntegrationEvent::log($provider, 'in', 'rejected', 'webhook', null, [], 'Gateway not set up or switched off');

            return 404;
        }

        try {
            $res = $gateway->webhook($request);
        } catch (InvalidWebhook $e) {
            IntegrationEvent::log($provider, 'in', 'rejected', 'webhook', null, ['ip' => $request->ip()], $e->getMessage());

            return 400;
        } catch (GatewayError $e) {
            // Signature fine but the provider could not be asked (try again later: the provider retries).
            IntegrationEvent::log($provider, 'in', 'failed', 'webhook', null, [], $e->getMessage());

            return 503;
        }

        $eventId = mb_substr((string) ($res['event_id'] ?? ''), 0, 191) ?: null;
        try {
            $event = IntegrationEvent::log($provider, 'in', 'pending', mb_substr((string) ($res['event'] ?? 'webhook'), 0, 80), $eventId,
                ['ref' => $res['ref'] ?? null, 'status' => $res['status'] ?? null], null, $request->getContent());
        } catch (UniqueConstraintViolationException) {
            $event = IntegrationEvent::query()->where('provider', $provider)->where('external_id', $eventId)->first();
            if ($event && in_array($event->status, ['ok', 'ignored', 'duplicate'], true)) {
                $event->increment('attempts');

                return 200; // already handled: never recorded twice
            }
            $event?->increment('attempts');
        }

        try {
            [$outcome, $note] = self::apply($gateway, $res);
            $event?->forceFill(['status' => $outcome, 'error' => $outcome === 'failed' ? $note : null, 'summary' => array_merge((array) $event->summary, ['result' => $note])])->save();

            return 200;
        } catch (\Throwable $e) {
            report($e);
            $event?->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return 500;
        }
    }

    /** Customer came back from checkout: check with the provider before saying anything. */
    public static function returned(PaymentRequest $r, string $provider, Request $request): void
    {
        if ($r->provider !== $provider || $r->isPaid()) {
            return;
        }
        $gateway = self::gateway($provider, connectedOnly: false);
        if (! $gateway) {
            return;
        }
        try {
            $res = $gateway->confirm($r, $request);
            if ($res) {
                IntegrationEvent::log($provider, 'out', 'ok', 'return check', null, ['ref' => $res['ref'] ?? null, 'status' => $res['status'] ?? null]);
                self::apply($gateway, $res);
            }
        } catch (\Throwable $e) {
            report($e); // the webhook will still confirm it
        }
    }

    /** Finds the payment link of a verified result and acts on it: [event status, note]. */
    public static function apply(Gateway $gateway, array $res): array
    {
        $ref = (string) ($res['ref'] ?? '');
        $number = preg_replace('/-\d+$/', '', $ref);
        $r = ($number ? PaymentRequest::query()->where('number', $number)->first() : null)
            ?? (filled($res['provider_ref'] ?? null) ? PaymentRequest::query()->where('provider', $gateway::key())->where('provider_ref', (string) $res['provider_ref'])->first() : null);
        if (! $r) {
            return ['ignored', 'No payment link matches '.($ref ?: ($res['provider_ref'] ?? '?'))];
        }

        return match ($res['status'] ?? null) {
            'paid' => self::settle($r, $gateway, $res),
            'failed' => self::mark($r, ['open', 'processing'], 'failed', $res['error'] ?? 'Payment declined'),
            'expired' => self::mark($r, ['processing'], 'open', 'Checkout expired'),
            'pending' => self::mark($r, ['open', 'failed'], 'processing', null),
            default => ['ignored', 'Nothing to do ('.($res['event'] ?? '?').')'],
        };
    }

    protected static function mark(PaymentRequest $r, array $from, string $to, ?string $error): array
    {
        $done = PaymentRequest::query()->whereKey($r->id)->whereIn('status', $from)->update(['status' => $to, 'last_error' => $error, 'updated_at' => now()]);

        return ['ok', $done ? $r->number.' → '.$to : $r->number.' unchanged ('.$r->status.')'];
    }

    /** Records the money once, for the exact amount asked. */
    public static function settle(PaymentRequest $r, Gateway $gateway, array $res): array
    {
        $notify = null;
        $out = DB::transaction(function () use ($r, $gateway, $res, &$notify) {
            $r = PaymentRequest::query()->lockForUpdate()->findOrFail($r->id);
            $paymentId = (string) ($res['payment_id'] ?? $res['provider_ref'] ?? '');

            if ($r->isPaid() && $r->provider_payment_id === $paymentId) {
                return ['duplicate', $r->number.' already recorded'];
            }

            [$minor, $currency] = $gateway->expected($r);
            if ((int) ($res['amount_minor'] ?? -1) !== $minor || strtoupper((string) ($res['currency'] ?? '')) !== $currency) {
                $why = 'Paid '.($res['currency'] ?? '?').' '.number_format(((int) ($res['amount_minor'] ?? 0)) / 100, 2).' but '.$currency.' '.number_format($minor / 100, 2).' was asked';
                $r->forceFill(['status' => 'review', 'last_error' => $why, 'provider' => $gateway::key(), 'provider_payment_id' => $paymentId])->save();
                $on = $r->booking ?? $r->quotation ?? $r;
                AuditLog::create(['event' => 'payment', 'outcome' => 'failed', 'auditable_type' => $on->getMorphClass(), 'auditable_id' => $on->getKey(),
                    'note' => mb_substr($r->number.': '.$why.' · not recorded, check it with '.$gateway->label(), 0, 255)]);
                $notify = ['Online payment to check: '.$r->number, $why.'. Nothing was recorded.', 'danger'];

                return ['ok', 'amount mismatch → review'];
            }

            $second = $r->isPaid(); // a different payment for a link already paid: the money is real, record it and flag it
            $booking = $r->booking_id ? Booking::find($r->booking_id) : null;
            $category = $r->purpose === 'security_deposit' ? 'deposit'
                : ($booking?->revenueCategory() ?? (new Booking(['service_type' => $r->quotation?->service_type, 'drive_mode' => $r->quotation?->drive_mode]))->revenueCategory());

            $t = Transaction::query()->create([
                'booking_id' => $booking?->id,
                'customer_id' => $r->customer_id,
                'type' => TransactionType::Income,
                'category' => $category,
                'date' => today(),
                'amount' => (float) $r->amount,
                'status' => PaymentStatus::Paid,
                'method' => 'online',
                'reference' => mb_substr($gateway::key().' '.$paymentId, 0, 191),
                'description' => mb_substr('Online payment · '.$gateway->label().' · '.$r->number.' · '.$r->forLabel(), 0, 255),
            ]);

            $r->forceFill($second
                ? ['status' => 'review', 'last_error' => 'Paid twice ('.$gateway->label().' '.$paymentId.'): refund one of the payments', 'meta' => array_replace((array) $r->meta, ['second_transaction' => $t->id])]
                : ['status' => 'paid', 'paid_at' => now(), 'provider' => $gateway::key(), 'mode' => $gateway->mode(), 'provider_payment_id' => $paymentId, 'transaction_id' => $t->id, 'last_error' => null]
            )->save();

            $for = $booking ?? $r->quotation;
            AuditLog::note($for ?? $r, 'payment', 'Online payment AED '.number_format((float) $r->amount, 2).' received via '.$gateway->label().' ('.$r->number.', '.$gateway->mode().')'.($second ? ' · SECOND payment, check' : ''));
            $notify = $second
                ? ['Paid twice: '.$r->number, 'A second online payment arrived for the same link. Refund one of them.', 'danger']
                : ['Online payment received: AED '.number_format((float) $r->amount, 2), $r->number.' · '.$r->forLabel().' · '.$gateway->label()
                    .($booking && $booking->fresh()->statusLabel() === 'Paid, documents pending'
                        ? ' · Paid, documents pending: confirm it once the documents are approved.'
                        : ($booking && $booking->status === BookingStatus::PendingPayment ? ' · The booking is still "Waiting for payment": confirm it if this was the advance.' : '')), 'success'];

            return ['ok', $r->number.' paid'];
        });

        if ($notify) {
            $r->refresh();
            $owner = $r->booking?->assignee ?? $r->quotation?->creator;
            $url = $r->booking_id ? \App\Filament\Resources\BookingResource::getUrl('edit', ['record' => $r->booking_id]) : null;
            DB::afterCommit(fn () => rescue(fn () => Notify::staff('manage-finance', $notify[0], $notify[1], $url, $owner, $notify[2], event: 'payment_check')));
        }

        return $out;
    }

    /** Refund through the gateway (finance only), then record it. */
    public static function refund(PaymentRequest $r, float $amount, string $reason, ?User $by = null): array
    {
        abort_unless(Access::allows('manage-finance'), 403);
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $r->refundable() + 0.004) {
            throw ValidationException::withMessages(['data.amount' => 'Up to AED '.number_format($r->refundable(), 2).' can be refunded.']);
        }
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['data.reason' => 'Say why.']);
        }
        $gateway = self::gateway((string) $r->provider, connectedOnly: false);
        if (! $gateway || ! $r->provider_payment_id) {
            throw ValidationException::withMessages(['data.amount' => 'The gateway is not set up any more. Refund it in the provider\'s dashboard, then record it in Accounts.']);
        }

        $out = $gateway->refund($r, $amount, $reason);

        DB::transaction(function () use ($r, $amount, $reason, $by, $out, $gateway) {
            $r = PaymentRequest::query()->lockForUpdate()->findOrFail($r->id);
            $t = $r->transaction_id ? Transaction::find($r->transaction_id) : null;
            $label = 'Refund · '.$gateway->label().' · '.$r->number.' · '.$r->forLabel();

            if ($r->purpose === 'security_deposit') {
                Transaction::query()->create(['booking_id' => $r->booking_id, 'customer_id' => $r->customer_id, 'type' => TransactionType::Expense, 'category' => 'deposit_refund',
                    'date' => today(), 'amount' => $amount, 'status' => PaymentStatus::Paid, 'method' => 'online', 'reference' => mb_substr($gateway::key().' '.$out['id'], 0, 191), 'description' => mb_substr($label, 0, 255)]);
            } elseif ($t && abs((float) $t->amount - $amount) < 0.005) {
                $t->update(['status' => PaymentStatus::Refunded, 'notes' => trim(($t->notes ? $t->notes."\n" : '').'Refunded online '.now()->format('d M Y').' · '.$reason)]);
            } elseif ($t) {
                // Part refund: the kept part stays a payment, the refunded part becomes its own "refunded" row.
                $t->update(['amount' => round((float) $t->amount - $amount, 2), 'notes' => trim(($t->notes ? $t->notes."\n" : '').'AED '.number_format($amount, 2).' refunded online '.now()->format('d M Y').' · '.$reason)]);
                Transaction::query()->create(['booking_id' => $t->booking_id, 'customer_id' => $t->customer_id, 'type' => TransactionType::Income, 'category' => $t->category,
                    'date' => today(), 'amount' => $amount, 'status' => PaymentStatus::Refunded, 'method' => 'online', 'reference' => mb_substr($gateway::key().' '.$out['id'], 0, 191), 'description' => mb_substr($label, 0, 255)]);
            }

            $refunded = round((float) $r->refunded_amount + $amount, 2);
            $r->forceFill([
                'refunded_amount' => $refunded,
                'status' => $refunded >= (float) $r->amount - 0.004 ? 'refunded' : 'partly_refunded',
                'meta' => array_replace((array) $r->meta, ['refunds' => [...(array) data_get($r->meta, 'refunds', []), ['id' => $out['id'], 'amount' => $amount, 'status' => $out['status'], 'reason' => $reason, 'by' => $by?->id ?? auth()->id(), 'at' => now()->toIso8601String()]]]),
            ])->save();
            AuditLog::note($r->booking ?? $r->quotation ?? $r, 'refund', 'Refunded AED '.number_format($amount, 2).' online via '.$gateway->label().' ('.$r->number.'): '.$reason.($out['status'] === 'pending' ? ' · provider says pending' : ''));
        });

        return $out;
    }

    /** Booking made from a quotation: payments already made on the quotation move onto the booking. */
    public static function moveToBooking(Quotation $q, Booking $b): int
    {
        $moved = 0;
        foreach (PaymentRequest::query()->where('quotation_id', $q->id)->whereNull('booking_id')->get() as $r) {
            $r->forceFill(['booking_id' => $b->id, 'customer_id' => $r->customer_id ?? $b->customer_id])->save();
            if ($r->transaction_id && ($t = Transaction::find($r->transaction_id)) && ! $t->booking_id) {
                $t->update(['booking_id' => $b->id, 'customer_id' => $t->customer_id ?? $b->customer_id, 'related_type' => $b->bookable_type, 'related_id' => $b->bookable_id]);
                $moved++;
            }
        }
        if ($moved) {
            $b->fresh()->syncBalance();
        }

        return $moved;
    }

    /** Money already paid online on a quotation (not yet on a booking). */
    public static function paidOnQuotation(Quotation $q): float
    {
        return round((float) PaymentRequest::query()->where('quotation_id', $q->id)->whereNull('booking_id')->whereIn('status', ['paid', 'partly_refunded'])->sum(DB::raw('amount - refunded_amount')), 2);
    }

    /** Cancel a link that has not been paid. */
    public static function cancel(PaymentRequest $r, ?string $why = null): bool
    {
        $done = PaymentRequest::query()->whereKey($r->id)->whereIn('status', ['open', 'processing', 'failed'])->update(['status' => 'cancelled', 'last_error' => $why, 'updated_at' => now()]);
        if ($done) {
            AuditLog::note($r->booking ?? $r->quotation ?? $r, 'payment_link', 'Payment link '.$r->number.' cancelled'.($why ? ': '.$why : ''));
        }

        return (bool) $done;
    }

    public static function registryEntries(): array
    {
        $webhook = fn (string $k) => '/api/webhooks/payments/'.$k;
        $limits = [
            'min_amount' => ['label' => 'Smallest amount (AED)', 'help' => 'Empty = no minimum. Use the limit agreed with the provider.'],
            'max_amount' => ['label' => 'Largest amount (AED)', 'help' => 'Empty = no maximum.'],
        ];

        return [
            'stripe' => [
                'label' => 'Stripe', 'group' => 'Payments', 'modes' => true, 'tester' => Gateways\Stripe::class, 'webhook' => $webhook('stripe'),
                'description' => 'Card, Apple Pay and Google Pay on Stripe\'s hosted checkout.',
                'docs' => 'https://docs.stripe.com/payments/checkout',
                'config' => [],
                'secrets' => [
                    'secret_key' => ['label' => 'Secret key (sk_test_… / sk_live_…)', 'help' => 'Developers › API keys. A restricted key needs Checkout Sessions (write), Refunds (write), Balance (read).'],
                    'webhook_secret' => ['label' => 'Webhook signing secret (whsec_…)', 'help' => 'Developers › Webhooks › add the CRM address below, events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired.'],
                ],
                'required' => ['secret_key', 'webhook_secret'],
                'requirements' => ['A Stripe account activated for the UAE (AED).', 'A webhook endpoint pointing to the CRM address shown below.'],
            ],
            'paymob' => [
                'label' => 'Paymob', 'group' => 'Payments', 'modes' => true, 'tester' => Gateways\Paymob::class, 'webhook' => $webhook('paymob'),
                'description' => 'Cards and local wallets on Paymob\'s Unified Checkout (UAE).',
                'docs' => 'https://developers.paymob.com/uae/getting-started-uae',
                'config' => [
                    'integration_ids' => ['label' => 'Integration ID(s)', 'help' => 'From Developers › Payment integrations, separated by commas (one per payment method, test or live).'],
                    'base_url' => ['label' => 'API address', 'help' => 'Normally https://uae.paymob.com', 'default' => 'https://uae.paymob.com'],
                ],
                'secrets' => [
                    'api_key' => ['label' => 'API key', 'help' => 'Settings › Account info.'],
                    'secret_key' => ['label' => 'Secret key', 'help' => 'Settings › Account info (test or live).'],
                    'public_key' => ['label' => 'Public key', 'help' => 'Settings › Account info (test or live).'],
                    'hmac_secret' => ['label' => 'HMAC secret', 'help' => 'Settings › Account info. Used to check every payment callback.'],
                ],
                'required' => ['api_key', 'secret_key', 'public_key', 'hmac_secret'],
                'requirements' => ['A Paymob UAE merchant account.', 'Set the "Transaction processed callback" of each integration to the CRM address below.'],
            ],
            'tabby' => [
                'label' => 'Tabby', 'group' => 'Payments', 'modes' => true, 'tester' => Gateways\Tabby::class, 'webhook' => $webhook('tabby'), 'bnpl' => true,
                'description' => 'Pay in 4 instalments. Tabby decides per customer; staff never see the decision reason beyond "not available".',
                'docs' => 'https://docs.tabby.ai/introduction/about-tabby',
                'config' => $limits + [
                    'merchant_code' => ['label' => 'Merchant code', 'help' => 'Given by Tabby (e.g. the store code for AED).'],
                    'webhook_header' => ['label' => 'Webhook header name', 'help' => 'The header name you registered with the webhook at Tabby.', 'default' => 'X-RP-Webhook'],
                ],
                'secrets' => [
                    'public_key' => ['label' => 'Public key (pk_…)', 'help' => 'Used to open the checkout.'],
                    'secret_key' => ['label' => 'Secret key (sk_…)', 'help' => 'Used to read, capture and refund payments.'],
                    'webhook_secret' => ['label' => 'Webhook header value', 'help' => 'A long random value; register the webhook at Tabby with this header so the CRM knows it is Tabby.'],
                ],
                'required' => ['public_key', 'secret_key', 'webhook_secret'],
                'requirements' => ['Merchant approval from Tabby (BNPL is not available until Tabby approves the business).', 'Webhook registered with Tabby (POST /api/v1/webhooks) to the CRM address below, with the header above.'],
            ],
            'tamara' => [
                'label' => 'Tamara', 'group' => 'Payments', 'modes' => true, 'tester' => Gateways\Tamara::class, 'webhook' => $webhook('tamara'), 'bnpl' => true,
                'description' => 'Pay later / in instalments. Tamara decides per customer.',
                'docs' => 'https://docs.tamara.co/',
                'config' => $limits,
                'secrets' => [
                    'api_token' => ['label' => 'API token', 'help' => 'Partners portal › Integration (sandbox or live).'],
                    'notification_token' => ['label' => 'Notification token', 'help' => 'Partners portal › Integration. Used to check every notification.'],
                ],
                'required' => ['api_token', 'notification_token'],
                'requirements' => ['Merchant approval from Tamara (BNPL is not available until Tamara approves the business).', 'The customer\'s mobile number on the booking.'],
            ],
            'paypal' => [
                'label' => 'PayPal', 'group' => 'Payments', 'modes' => true, 'tester' => Gateways\PayPal::class, 'webhook' => $webhook('paypal'),
                'description' => 'PayPal checkout. PayPal does not accept AED: the amount is converted at the rate you set.',
                'docs' => 'https://developer.paypal.com/docs/checkout/',
                'config' => [
                    'currency' => ['label' => 'PayPal currency', 'help' => 'e.g. USD. Required.', 'options' => ['USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP']],
                    'aed_rate' => ['label' => 'AED per 1 unit of that currency', 'help' => 'e.g. 3.6725 for USD. Required; you decide the rate.'],
                    'webhook_id' => ['label' => 'Webhook ID', 'help' => 'From the PayPal app\'s webhook (event PAYMENT.CAPTURE.COMPLETED).'],
                ],
                'secrets' => [
                    'client_id' => ['label' => 'Client ID'],
                    'client_secret' => ['label' => 'Client secret'],
                ],
                'required' => ['client_id', 'client_secret'],
                'requirements' => ['A PayPal Business account and a REST app (sandbox or live).', 'A currency and rate (PayPal has no AED).'],
            ],
        ];
    }
}
