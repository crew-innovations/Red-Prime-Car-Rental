<?php

namespace App\Support\Payments\Gateways;

use App\Models\Integration;
use App\Models\PaymentRequest;
use App\Support\Payments\Gateway;
use App\Support\Payments\GatewayError;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Tabby (pay in 4). Docs: https://docs.tabby.ai. Needs merchant approval from Tabby.
 * A webhook only tells us to look: the payment is fetched with the secret key,
 * checked (AUTHORIZED, same amount and currency), then captured. Money is
 * recorded only after the capture succeeds.
 */
class Tabby extends Gateway
{
    protected const API = 'https://api.tabby.ai/api';

    public static function key(): string
    {
        return 'tabby';
    }

    public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array
    {
        $amount = self::money((float) $r->amount);
        $body = $this->ok($this->http()->withToken($this->secret('public_key'))->asJson()->post(self::API.'/v2/checkout', [
            'payment' => [
                'amount' => $amount,
                'currency' => strtoupper($r->currency ?: 'AED'),
                'description' => mb_substr($r->description ?: $r->number, 0, 200),
                'buyer' => ['name' => $r->customerName() ?: 'Customer', 'email' => $r->customerEmail(), 'phone' => $r->customerPhone()],
                'order' => ['reference_id' => $r->attemptRef(), 'items' => [['title' => mb_substr($r->description ?: $r->number, 0, 100), 'quantity' => 1, 'unit_price' => $amount, 'category' => 'Rental services', 'reference_id' => $r->number]]],
                'buyer_history' => ['registered_since' => ($r->customer?->created_at ?? now())->toIso8601String(), 'loyalty_level' => 0],
                'shipping_address' => ['city' => 'Dubai', 'address' => 'Service booking (no shipping)', 'zip' => '00000'],
                'meta' => ['order_id' => $r->attemptRef(), 'customer' => (string) $r->customer_id],
            ],
            'lang' => 'en',
            'merchant_code' => (string) $this->integration->config('merchant_code'),
            'merchant_urls' => ['success' => $returnUrl, 'cancel' => $cancelUrl, 'failure' => $cancelUrl.(str_contains($cancelUrl, '?') ? '&' : '?').'failed=1'],
        ]), 'Create checkout', $r->attemptRef());

        if (($body['status'] ?? null) !== 'created') {
            $why = data_get($body, 'configuration.products.installments.rejection_reason');
            throw new GatewayError('Tabby is not available for this payment'.($why ? ' ('.str_replace('_', ' ', $why).')' : '').'.');
        }

        return ['url' => data_get($body, 'configuration.available_products.installments.0.web_url'), 'provider_ref' => data_get($body, 'payment.id')];
    }

    public function webhook(Request $request): array
    {
        $expected = $this->integration->secret('webhook_secret');
        $header = (string) ($this->integration->config('webhook_header') ?: 'X-RP-Webhook');
        if (! $expected || ! hash_equals($expected, (string) $request->header($header))) {
            throw new InvalidWebhook('Webhook header does not match');
        }
        $id = (string) data_get($request->json()->all(), 'id');
        if ($id === '') {
            throw new InvalidWebhook('No payment id');
        }

        return $this->verify($id, 'webhook');
    }

    public function confirm(PaymentRequest $r, Request $request): ?array
    {
        $id = (string) $request->query('payment_id');

        return $id !== '' && $id === $r->provider_ref ? $this->verify($id, 'return') : null;
    }

    /** Reads the payment from Tabby and captures it when it is authorised. */
    protected function verify(string $id, string $via): array
    {
        $p = $this->ok($this->http()->withToken($this->secret('secret_key'))->get(self::API.'/v2/payments/'.urlencode($id)), 'Read payment', $id);
        $status = strtoupper((string) ($p['status'] ?? ''));
        $captured = collect($p['captures'] ?? [])->sum(fn ($c) => (float) ($c['amount'] ?? 0));

        if ($status === 'AUTHORIZED' && $captured <= 0) {
            $p = $this->ok($this->http()->withToken($this->secret('secret_key'))->asJson()
                ->post(self::API.'/v2/payments/'.urlencode($id).'/captures', ['amount' => (string) $p['amount'], 'reference_id' => data_get($p, 'order.reference_id')]), 'Capture', $id);
            $status = strtoupper((string) ($p['status'] ?? ''));
            $captured = collect($p['captures'] ?? [])->sum(fn ($c) => (float) ($c['amount'] ?? 0));
        }

        $result = match (true) {
            $captured > 0 && in_array($status, ['CLOSED', 'AUTHORIZED'], true) => 'paid',
            in_array($status, ['REJECTED'], true) => 'failed',
            in_array($status, ['EXPIRED'], true) => 'expired',
            default => 'pending',
        };

        return [
            'status' => $result,
            'ref' => data_get($p, 'order.reference_id'),
            'provider_ref' => $id,
            'payment_id' => $id,
            'amount_minor' => $captured > 0 ? (int) round($captured * 100) : (int) round((float) ($p['amount'] ?? 0) * 100),
            'currency' => strtoupper((string) ($p['currency'] ?? '')),
            'event_id' => 'tabby-'.$id.'-'.$result,
            'event' => $via.' '.strtolower($status),
        ];
    }

    public function refund(PaymentRequest $r, float $amount, string $reason): array
    {
        $body = $this->ok($this->http()->withToken($this->secret('secret_key'))->asJson()
            ->post(self::API.'/v2/payments/'.urlencode((string) $r->provider_payment_id).'/refunds', ['amount' => self::money($amount), 'reason' => mb_substr($reason, 0, 200)]),
            'Refund', $r->number);

        return ['id' => (string) data_get($body, 'refunds.0.id', $r->provider_payment_id), 'status' => 'succeeded'];
    }

    public static function test(Integration $i): array
    {
        foreach (['public_key' => 'Public key', 'secret_key' => 'Secret key', 'webhook_secret' => 'Webhook header value'] as $k => $label) {
            if (! $i->secret($k)) {
                return [false, $label.' not saved'];
            }
        }
        if (blank($i->config('merchant_code'))) {
            return [false, 'Merchant code not set'];
        }
        try {
            $res = Http::timeout(15)->withToken($i->secret('secret_key'))->withHeaders(['X-Merchant-Code' => (string) $i->config('merchant_code')])->acceptJson()->get(self::API.'/v1/webhooks');
        } catch (\Throwable $e) {
            return [false, 'Could not reach Tabby: '.class_basename($e)];
        }
        if ($res->failed()) {
            return [false, 'Secret key refused (HTTP '.$res->status().')'];
        }
        $ours = collect((array) $res->json())->contains(fn ($w) => str_contains((string) ($w['url'] ?? ''), '/webhooks/payments/tabby'));

        return $ours ? [true, null, 'Tabby · '.$i->config('merchant_code').' · '.$i->mode]
            : [false, 'Keys work, but the CRM webhook is not registered with Tabby yet ('.route('payments.webhook', 'tabby').').'];
    }
}
