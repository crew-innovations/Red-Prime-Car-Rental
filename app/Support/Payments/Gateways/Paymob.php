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
 * Paymob (UAE) Intention API + Unified Checkout. Docs: https://developers.paymob.com/uae
 * The "transaction processed" callback is checked with the HMAC secret (SHA-512
 * over the documented fields, in order).
 */
class Paymob extends Gateway
{
    public const HMAC_FIELDS = ['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
        'source_data.sub_type', 'source_data.type', 'success'];

    public static function key(): string
    {
        return 'paymob';
    }

    protected static function base(Integration $i): string
    {
        return rtrim((string) ($i->config('base_url') ?: 'https://uae.paymob.com'), '/');
    }

    public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array
    {
        $methods = collect(preg_split('/[\s,]+/', (string) $this->integration->config('integration_ids')))->filter(fn ($v) => ctype_digit((string) $v))->map(fn ($v) => (int) $v)->values()->all();
        if (! $methods) {
            throw new GatewayError('Paymob: add the integration ID(s) in Settings › Integrations.');
        }
        [$first, $last] = self::names($r->customerName());
        $body = $this->ok($this->http()->withHeaders(['Authorization' => 'Token '.$this->secret('secret_key')])->asJson()->post(self::base($this->integration).'/v1/intention/', [
            'amount' => $r->minor(),
            'currency' => strtoupper($r->currency ?: 'AED'),
            'payment_methods' => $methods,
            'items' => [['name' => mb_substr($r->description ?: $r->number, 0, 50), 'amount' => $r->minor(), 'description' => $r->number.' · '.$r->forLabel(), 'quantity' => 1]],
            'billing_data' => ['first_name' => $first, 'last_name' => $last, 'email' => $r->customerEmail() ?: 'no-email@redprimerentals.com', 'phone_number' => $r->customerPhone() ?: '+971000000000'],
            'special_reference' => $r->attemptRef(),
            'notification_url' => route('payments.webhook', 'paymob'),
            'redirection_url' => $returnUrl,
        ]), 'Create intention', $r->attemptRef());

        $secret = $body['client_secret'] ?? null;
        if (! $secret) {
            throw new GatewayError('Paymob: no checkout was returned.');
        }

        return [
            'url' => self::base($this->integration).'/unifiedcheckout/?publicKey='.urlencode($this->secret('public_key')).'&clientSecret='.urlencode($secret),
            'provider_ref' => (string) ($body['intention_order_id'] ?? $body['id'] ?? ''),
            'meta' => ['intention' => $body['id'] ?? null],
        ];
    }

    public static function hmacFor(array $obj, string $secret): string
    {
        $concat = collect(self::HMAC_FIELDS)->map(function ($f) use ($obj) {
            $v = data_get($obj, $f);

            return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        })->implode('');

        return hash_hmac('sha512', $concat, $secret);
    }

    public function webhook(Request $request): array
    {
        $secret = $this->integration->secret('hmac_secret');
        if (! $secret) {
            throw new InvalidWebhook('HMAC secret not saved');
        }
        $payload = json_decode($request->getContent(), true);
        $obj = is_array($payload) ? (array) ($payload['obj'] ?? []) : [];
        $hmac = (string) $request->query('hmac', $request->header('hmac', ''));
        if (! $obj || $hmac === '') {
            throw new InvalidWebhook('Missing transaction or hmac');
        }
        if (! hash_equals(self::hmacFor($obj, $secret), strtolower($hmac))) {
            throw new InvalidWebhook('HMAC does not match');
        }
        if (($payload['type'] ?? 'TRANSACTION') !== 'TRANSACTION') {
            return ['status' => 'ignored', 'event_id' => 'paymob-'.($payload['type'] ?? 'x').'-'.($obj['id'] ?? uniqid()), 'event' => (string) ($payload['type'] ?? '')];
        }

        $success = filter_var($obj['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $pending = filter_var($obj['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $undone = filter_var($obj['is_refunded'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($obj['is_voided'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($obj['has_parent_transaction'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = match (true) {
            $undone => 'ignored',          // refunds / voids we started ourselves
            $pending => 'pending',
            $success => 'paid',
            default => 'failed',
        };

        return [
            'status' => $status,
            'ref' => data_get($obj, 'order.merchant_order_id') ?? data_get($obj, 'special_reference'),
            'provider_ref' => (string) data_get($obj, 'order.id'),
            'payment_id' => (string) ($obj['id'] ?? ''),
            'amount_minor' => isset($obj['amount_cents']) ? (int) $obj['amount_cents'] : null,
            'currency' => isset($obj['currency']) ? strtoupper((string) $obj['currency']) : null,
            'event_id' => 'txn-'.($obj['id'] ?? '').'-'.$status,
            'event' => 'transaction',
            'error' => $status === 'failed' ? (string) (data_get($obj, 'data.message') ?: 'Declined') : null,
        ];
    }

    public function refund(PaymentRequest $r, float $amount, string $reason): array
    {
        $body = $this->ok($this->http()->withHeaders(['Authorization' => 'Token '.$this->secret('secret_key')])->asJson()
            ->post(self::base($this->integration).'/api/acceptance/void_refund/refund', ['transaction_id' => $r->provider_payment_id, 'amount_cents' => (int) round($amount * 100)]),
            'Refund', $r->number);
        if (array_key_exists('success', $body) && ! filter_var($body['success'], FILTER_VALIDATE_BOOLEAN) && ! filter_var($body['pending'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new GatewayError('Paymob: refund declined'.(data_get($body, 'data.message') ? ' · '.data_get($body, 'data.message') : ''));
        }

        return ['id' => (string) ($body['id'] ?? ''), 'status' => filter_var($body['pending'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'pending' : 'succeeded'];
    }

    public static function test(Integration $i): array
    {
        foreach (['api_key' => 'API key', 'secret_key' => 'Secret key', 'public_key' => 'Public key', 'hmac_secret' => 'HMAC secret'] as $k => $label) {
            if (! $i->secret($k)) {
                return [false, $label.' not saved'];
            }
        }
        if (blank($i->config('integration_ids'))) {
            return [false, 'Add the integration ID(s) (card, Apple Pay…) from the Paymob dashboard.'];
        }
        // Paymob keys carry their mode, e.g. are_sk_test_… / are_sk_live_…
        if (! str_contains((string) $i->secret('secret_key'), 'sk_'.($i->mode === 'live' ? 'live' : 'test'))) {
            return [false, 'The secret key does not look like a '.$i->mode.' key.'];
        }
        try {
            $res = Http::timeout(15)->acceptJson()->asJson()->post(self::base($i).'/api/auth/tokens', ['api_key' => $i->secret('api_key')]);
        } catch (\Throwable $e) {
            return [false, 'Could not reach Paymob: '.class_basename($e)];
        }
        if ($res->failed() || ! data_get($res->json(), 'token')) {
            return [false, 'API key refused (HTTP '.$res->status().')'];
        }

        return [true, null, 'Paymob · '.(data_get($res->json(), 'profile.company_name') ?: 'merchant '.data_get($res->json(), 'profile.id')).' · '.$i->mode];
    }
}
