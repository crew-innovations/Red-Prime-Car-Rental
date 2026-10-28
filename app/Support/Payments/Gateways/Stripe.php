<?php

namespace App\Support\Payments\Gateways;

use App\Models\Integration;
use App\Models\PaymentRequest;
use App\Support\Payments\Gateway;
use App\Support\Payments\InvalidWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (hosted page). Docs: https://docs.stripe.com/api/checkout/sessions,
 * webhooks signed with the endpoint secret (Stripe-Signature, HMAC-SHA256).
 */
class Stripe extends Gateway
{
    protected const API = 'https://api.stripe.com/v1';

    protected const TOLERANCE = 300; // seconds a signed webhook stays valid

    public static function key(): string
    {
        return 'stripe';
    }

    public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array
    {
        $params = [
            'mode' => 'payment',
            'client_reference_id' => $r->attemptRef(),
            'success_url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => strtolower($r->currency ?: 'AED'), 'unit_amount' => $r->minor(), 'product_data' => ['name' => mb_substr($r->description ?: 'Payment '.$r->number, 0, 250)]],
            ]],
            'metadata' => ['payment_request' => $r->number, 'attempt' => $r->attemptRef()],
            'payment_intent_data' => ['metadata' => ['payment_request' => $r->number, 'attempt' => $r->attemptRef()], 'description' => $r->number.' · '.$r->forLabel()],
        ];
        if (filter_var($r->customerEmail(), FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = $r->customerEmail();
        }
        $body = $this->ok($this->http()->withToken($this->secret('secret_key'))->asForm()
            ->withHeaders(['Idempotency-Key' => 'rp-'.$r->attemptRef()])
            ->post(self::API.'/checkout/sessions', $params), 'Create checkout', $r->attemptRef());

        return ['url' => $body['url'] ?? null, 'provider_ref' => $body['id'] ?? null];
    }

    public function webhook(Request $request): array
    {
        $raw = $request->getContent();
        $secret = $this->integration->secret('webhook_secret');
        if (! $secret) {
            throw new InvalidWebhook('Webhook signing secret not saved');
        }
        $parts = collect(explode(',', (string) $request->header('Stripe-Signature')))
            ->map(fn ($p) => explode('=', trim($p), 2))->filter(fn ($p) => count($p) === 2);
        $t = (int) ($parts->first(fn ($p) => $p[0] === 't')[1] ?? 0);
        $sigs = $parts->filter(fn ($p) => $p[0] === 'v1')->pluck(1);
        if (! $t || $sigs->isEmpty()) {
            throw new InvalidWebhook('Missing Stripe-Signature');
        }
        if (abs(time() - $t) > self::TOLERANCE) {
            throw new InvalidWebhook('Signature too old');
        }
        $expected = hash_hmac('sha256', $t.'.'.$raw, $secret);
        if (! $sigs->contains(fn ($s) => hash_equals($expected, (string) $s))) {
            throw new InvalidWebhook('Signature does not match');
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || empty($event['id'])) {
            throw new InvalidWebhook('Unreadable event');
        }

        $type = (string) ($event['type'] ?? '');
        $o = (array) data_get($event, 'data.object', []);
        $status = match ($type) {
            'checkout.session.completed' => ($o['payment_status'] ?? null) === 'paid' ? 'paid' : 'pending',
            'checkout.session.async_payment_succeeded' => 'paid',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'expired',
            default => 'ignored',
        };

        return $this->session($o, $status) + ['event_id' => $event['id'], 'event' => $type];
    }

    public function confirm(PaymentRequest $r, Request $request): ?array
    {
        $id = (string) $request->query('session_id');
        if ($id === '' || $id !== $r->provider_ref) {
            return null;
        }
        $o = $this->ok($this->http()->withToken($this->secret('secret_key'))->get(self::API.'/checkout/sessions/'.urlencode($id)), 'Check checkout', $r->attemptRef());

        return $this->session($o, ($o['payment_status'] ?? null) === 'paid' ? 'paid' : (($o['status'] ?? null) === 'expired' ? 'expired' : 'pending'))
            + ['event_id' => 'return-'.$id.'-'.($o['payment_status'] ?? 'x'), 'event' => 'return'];
    }

    protected function session(array $o, string $status): array
    {
        return [
            'status' => $status,
            'ref' => $o['client_reference_id'] ?? data_get($o, 'metadata.attempt'),
            'provider_ref' => $o['id'] ?? null,
            'payment_id' => is_string($o['payment_intent'] ?? null) ? $o['payment_intent'] : data_get($o, 'payment_intent.id'),
            'amount_minor' => isset($o['amount_total']) ? (int) $o['amount_total'] : null,
            'currency' => isset($o['currency']) ? strtoupper($o['currency']) : null,
        ];
    }

    public function refund(PaymentRequest $r, float $amount, string $reason): array
    {
        $body = $this->ok($this->http()->withToken($this->secret('secret_key'))->asForm()
            ->withHeaders(['Idempotency-Key' => 'rp-refund-'.$r->number.'-'.((int) round((float) $r->refunded_amount * 100)).'-'.(int) round($amount * 100)])
            ->post(self::API.'/refunds', ['payment_intent' => $r->provider_payment_id, 'amount' => (int) round($amount * 100), 'metadata' => ['payment_request' => $r->number, 'reason' => mb_substr($reason, 0, 200)]]),
            'Refund', $r->number);
        if (in_array($body['status'] ?? '', ['failed', 'canceled'], true)) {
            throw new \App\Support\Payments\GatewayError('Stripe: refund '.$body['status']);
        }

        return ['id' => $body['id'] ?? null, 'status' => ($body['status'] ?? '') === 'succeeded' ? 'succeeded' : 'pending'];
    }

    public static function test(Integration $i): array
    {
        $key = $i->secret('secret_key');
        if (! $key) {
            return [false, 'Secret key not saved'];
        }
        try {
            $res = Http::timeout(15)->withToken($key)->acceptJson()->get(self::API.'/balance');
        } catch (\Throwable $e) {
            return [false, 'Could not reach Stripe: '.class_basename($e)];
        }
        if ($res->failed()) {
            return [false, (string) (data_get($res->json(), 'error.message') ?? 'HTTP '.$res->status())];
        }
        $live = (bool) data_get($res->json(), 'livemode');
        if ($live !== ($i->mode === 'live')) {
            return [false, 'This is a '.($live ? 'live' : 'test').' key but the mode is set to '.$i->mode.'.'];
        }
        if (! $i->secret('webhook_secret')) {
            return [false, 'API key works. Add the webhook signing secret so payments can be confirmed.'];
        }

        return [true, null, 'Stripe · '.($live ? 'live' : 'test mode')];
    }
}
