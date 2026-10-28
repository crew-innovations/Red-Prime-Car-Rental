<?php

namespace App\Support\Payments\Gateways;

use App\Models\Integration;
use App\Models\PaymentRequest;
use App\Support\Payments\Gateway;
use App\Support\Payments\GatewayError;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PayPal Orders v2. Docs: https://developer.paypal.com/docs/api/orders/v2/
 * PayPal does not accept AED, so a currency and an AED rate must be set; without
 * them PayPal is not offered. The order is captured from the server when the
 * customer returns; webhooks are checked with PayPal's verify-webhook-signature API.
 */
class PayPal extends Gateway
{
    public static function key(): string
    {
        return 'paypal';
    }

    protected static function base(Integration $i): string
    {
        return $i->mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    protected static function token(Integration $i): string
    {
        $cacheKey = 'paypal-token-'.$i->mode.'-'.substr(hash('sha256', (string) $i->secret('client_id')), 0, 12);

        return Cache::remember($cacheKey, 300, function () use ($i) {
            $res = Http::timeout(15)->asForm()->acceptJson()->withBasicAuth((string) $i->secret('client_id'), (string) $i->secret('client_secret'))
                ->post(self::base($i).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if ($res->failed() || ! data_get($res->json(), 'access_token')) {
                throw new GatewayError('PayPal: client ID / secret refused (HTTP '.$res->status().').');
            }

            return (string) data_get($res->json(), 'access_token');
        });
    }

    protected function api(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http()->withToken(self::token($this->integration))->asJson();
    }

    public function refuses(PaymentRequest $r): ?string
    {
        if (blank($this->integration->config('currency')) || (float) $this->integration->config('aed_rate') <= 0) {
            return 'PayPal currency and AED rate not set';
        }

        return parent::refuses($r);
    }

    /** Amount in the PayPal currency, as sent to PayPal. */
    public function foreign(PaymentRequest $r): array
    {
        $rate = (float) $this->integration->config('aed_rate');

        return [round((float) $r->amount / $rate, 2), strtoupper((string) $this->integration->config('currency'))];
    }

    public function expected(PaymentRequest $r): array
    {
        $sent = data_get($r->meta, 'paypal.amount');
        $cur = data_get($r->meta, 'paypal.currency');
        if ($sent === null) {
            [$sent, $cur] = $this->foreign($r);
        }

        return [(int) round((float) $sent * 100), strtoupper((string) $cur)];
    }

    public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array
    {
        if ($why = $this->refuses($r)) {
            throw new GatewayError($why);
        }
        [$amount, $cur] = $this->foreign($r);
        $body = $this->ok($this->api()->withHeaders(['PayPal-Request-Id' => 'rp-'.$r->attemptRef()])->post(self::base($this->integration).'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $r->number, 'custom_id' => $r->attemptRef(), 'invoice_id' => $r->attemptRef(),
                'description' => mb_substr($r->description ?: $r->number, 0, 120),
                'amount' => ['currency_code' => $cur, 'value' => self::money($amount)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING', 'brand_name' => 'RED PRIME RENTALS',
            ]]],
        ]), 'Create order', $r->attemptRef());

        $url = collect($body['links'] ?? [])->firstWhere('rel', 'payer-action')['href'] ?? collect($body['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return ['url' => $url, 'provider_ref' => $body['id'] ?? null, 'meta' => ['paypal' => ['amount' => $amount, 'currency' => $cur, 'rate' => (float) $this->integration->config('aed_rate')]]];
    }

    public function confirm(PaymentRequest $r, Request $request): ?array
    {
        $order = (string) $request->query('token');
        if ($order === '' || $order !== $r->provider_ref) {
            return null;
        }
        $res = $this->api()->withHeaders(['PayPal-Request-Id' => 'rp-capture-'.$r->attemptRef()])->withBody('{}', 'application/json')
            ->post(self::base($this->integration).'/v2/checkout/orders/'.urlencode($order).'/capture');
        if ($res->status() === 422 && data_get($res->json(), 'details.0.issue') === 'ORDER_ALREADY_CAPTURED') {
            $res = $this->api()->get(self::base($this->integration).'/v2/checkout/orders/'.urlencode($order));
        }
        $o = $this->ok($res, 'Capture', $r->attemptRef());
        $cap = (array) data_get($o, 'purchase_units.0.payments.captures.0', []);

        return [
            'status' => ($cap['status'] ?? null) === 'COMPLETED' ? 'paid' : (($cap['status'] ?? null) === 'DECLINED' ? 'failed' : 'pending'),
            'ref' => $cap['custom_id'] ?? data_get($o, 'purchase_units.0.custom_id') ?? $r->attemptRef(),
            'provider_ref' => $order,
            'payment_id' => $cap['id'] ?? null,
            'amount_minor' => isset($cap['amount']['value']) ? (int) round((float) $cap['amount']['value'] * 100) : null,
            'currency' => $cap['amount']['currency_code'] ?? null,
            'event_id' => 'return-'.$order.'-'.($cap['status'] ?? 'x'),
            'event' => 'return capture',
        ];
    }

    public function webhook(Request $request): array
    {
        $webhookId = (string) $this->integration->config('webhook_id');
        if ($webhookId === '') {
            throw new InvalidWebhook('Webhook ID not set');
        }
        $event = json_decode($request->getContent(), true);
        if (! is_array($event) || empty($event['id'])) {
            throw new InvalidWebhook('Unreadable event');
        }
        $check = $this->api()->post(self::base($this->integration).'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'), 'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'), 'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'), 'webhook_id' => $webhookId, 'webhook_event' => $event,
        ]);
        if ($check->failed() || data_get($check->json(), 'verification_status') !== 'SUCCESS') {
            throw new InvalidWebhook('PayPal did not confirm the signature');
        }
        $type = (string) ($event['event_type'] ?? '');
        $res = (array) ($event['resource'] ?? []);
        $status = match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.DENIED' => 'failed',
            default => 'ignored',
        };

        return [
            'status' => $status, 'ref' => $res['custom_id'] ?? null, 'provider_ref' => data_get($res, 'supplementary_data.related_ids.order_id'),
            'payment_id' => $res['id'] ?? null, 'amount_minor' => isset($res['amount']['value']) ? (int) round((float) $res['amount']['value'] * 100) : null,
            'currency' => $res['amount']['currency_code'] ?? null, 'event_id' => $event['id'], 'event' => $type,
        ];
    }

    public function refund(PaymentRequest $r, float $amount, string $reason): array
    {
        $rate = (float) (data_get($r->meta, 'paypal.rate') ?: $this->integration->config('aed_rate'));
        $cur = (string) (data_get($r->meta, 'paypal.currency') ?: $this->integration->config('currency'));
        $body = $this->ok($this->api()->post(self::base($this->integration).'/v2/payments/captures/'.urlencode((string) $r->provider_payment_id).'/refund', [
            'amount' => ['value' => self::money(round($amount / max($rate, 0.0001), 2)), 'currency_code' => strtoupper($cur)], 'note_to_payer' => mb_substr($reason, 0, 255),
        ]), 'Refund', $r->number);

        return ['id' => $body['id'] ?? null, 'status' => ($body['status'] ?? '') === 'COMPLETED' ? 'succeeded' : 'pending'];
    }

    public static function test(Integration $i): array
    {
        if (! $i->secret('client_id') || ! $i->secret('client_secret')) {
            return [false, 'Client ID and secret not saved'];
        }
        if (blank($i->config('currency')) || (float) $i->config('aed_rate') <= 0) {
            return [false, 'PayPal does not take AED: set the PayPal currency and the AED rate first.'];
        }
        try {
            Cache::forget('paypal-token-'.$i->mode.'-'.substr(hash('sha256', (string) $i->secret('client_id')), 0, 12));
            self::token($i);
        } catch (\Throwable $e) {
            return [false, $e instanceof GatewayError ? $e->getMessage() : 'Could not reach PayPal: '.class_basename($e)];
        }

        return [true, null, 'PayPal · '.($i->mode === 'live' ? 'live' : 'sandbox').' · '.strtoupper((string) $i->config('currency'))];
    }
}
