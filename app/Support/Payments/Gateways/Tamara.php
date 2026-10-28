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
 * Tamara (pay later / in instalments). Docs: https://docs.tamara.co. Needs merchant
 * approval from Tamara. Notifications carry a JWT signed with the notification
 * token (HS256). An approved order is read back, authorised and captured; money
 * is recorded only after the capture.
 */
class Tamara extends Gateway
{
    public static function key(): string
    {
        return 'tamara';
    }

    protected static function base(Integration $i): string
    {
        return $i->mode === 'live' ? 'https://api.tamara.co' : 'https://api-sandbox.tamara.co';
    }

    protected function api(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http()->withToken($this->secret('api_token'))->asJson();
    }

    public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array
    {
        [$first, $last] = self::names($r->customerName());
        $cur = strtoupper($r->currency ?: 'AED');
        $money = fn (float $v) => ['amount' => round($v, 2), 'currency' => $cur];
        $phone = $r->customerPhone();
        if (! $phone) {
            throw new GatewayError('Tamara needs the customer\'s mobile number.');
        }
        $body = $this->ok($this->api()->post(self::base($this->integration).'/checkout', [
            'order_reference_id' => $r->attemptRef(),
            'order_number' => $r->attemptRef(),
            'total_amount' => $money((float) $r->amount),
            'description' => mb_substr($r->description ?: $r->number, 0, 250),
            'country_code' => 'AE',
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => 'en_US',
            'items' => [['reference_id' => $r->number, 'type' => 'Digital', 'name' => mb_substr($r->description ?: $r->number, 0, 100), 'sku' => $r->number, 'quantity' => 1, 'total_amount' => $money((float) $r->amount)]],
            'consumer' => ['first_name' => $first, 'last_name' => $last, 'phone_number' => $phone, 'email' => $r->customerEmail()],
            'shipping_address' => ['first_name' => $first, 'last_name' => $last, 'line1' => 'Service booking (no shipping)', 'city' => 'Dubai', 'country_code' => 'AE', 'phone_number' => $phone],
            'tax_amount' => $money(0),
            'shipping_amount' => $money(0),
            'merchant_url' => ['success' => $returnUrl, 'failure' => $cancelUrl.(str_contains($cancelUrl, '?') ? '&' : '?').'failed=1', 'cancel' => $cancelUrl, 'notification' => route('payments.webhook', 'tamara')],
        ]), 'Create checkout', $r->attemptRef());

        return ['url' => $body['checkout_url'] ?? null, 'provider_ref' => $body['order_id'] ?? null, 'meta' => ['checkout_id' => $body['checkout_id'] ?? null]];
    }

    /** HS256 JWT check (no extra library). */
    public static function jwtValid(string $jwt, string $secret): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        $b64 = fn (string $s) => base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
        $header = json_decode((string) $b64($parts[0]), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            return false;
        }
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($sig, $parts[2])) {
            return false;
        }
        $claims = json_decode((string) $b64($parts[1]), true);

        return ! isset($claims['exp']) || (int) $claims['exp'] >= time() - 60;
    }

    public function webhook(Request $request): array
    {
        $secret = $this->integration->secret('notification_token');
        $jwt = (string) ($request->query('tamaraToken') ?: $request->bearerToken());
        if (! $secret || $jwt === '' || ! self::jwtValid($jwt, $secret)) {
            throw new InvalidWebhook('Notification token does not match');
        }
        $orderId = (string) data_get($request->json()->all(), 'order_id');
        if ($orderId === '') {
            throw new InvalidWebhook('No order id');
        }

        return $this->verify($orderId, 'notification '.data_get($request->json()->all(), 'order_status', data_get($request->json()->all(), 'event_type')));
    }

    public function confirm(PaymentRequest $r, Request $request): ?array
    {
        $id = (string) $request->query('orderId');

        return $id !== '' && $id === $r->provider_ref ? $this->verify($id, 'return') : null;
    }

    protected function verify(string $orderId, string $via): array
    {
        $base = self::base($this->integration);
        $o = $this->ok($this->api()->get($base.'/orders/'.urlencode($orderId)), 'Read order', $orderId);
        $status = strtolower((string) ($o['status'] ?? ''));
        $total = (array) ($o['total_amount'] ?? []);

        if ($status === 'approved') {
            $this->ok($this->api()->post($base.'/orders/'.urlencode($orderId).'/authorise'), 'Authorise', $orderId);
            $status = 'authorised';
        }
        if ($status === 'authorised') {
            $cap = $this->ok($this->api()->post($base.'/payments/capture', [
                'order_id' => $orderId, 'total_amount' => $total,
                'shipping_info' => ['shipped_at' => now()->toIso8601String(), 'shipping_company' => 'Service (no shipping)'],
            ]), 'Capture', $orderId);
            $status = strtolower((string) ($cap['status'] ?? 'fully_captured'));
        }

        $result = match (true) {
            in_array($status, ['fully_captured', 'partially_captured'], true) => 'paid',
            in_array($status, ['declined', 'canceled', 'cancelled'], true) => 'failed',
            $status === 'expired' => 'expired',
            default => 'pending',
        };

        return [
            'status' => $result,
            'ref' => $o['order_reference_id'] ?? null,
            'provider_ref' => $orderId,
            'payment_id' => $orderId,
            'amount_minor' => isset($total['amount']) ? (int) round((float) $total['amount'] * 100) : null,
            'currency' => strtoupper((string) ($total['currency'] ?? '')),
            'event_id' => 'tamara-'.$orderId.'-'.$result,
            'event' => trim($via.' '.$status),
        ];
    }

    public function refund(PaymentRequest $r, float $amount, string $reason): array
    {
        $body = $this->ok($this->api()->post(self::base($this->integration).'/payments/simplified-refund/'.urlencode((string) $r->provider_payment_id), [
            'total_amount' => ['amount' => round($amount, 2), 'currency' => strtoupper($r->currency ?: 'AED')], 'comment' => mb_substr($reason, 0, 200),
        ]), 'Refund', $r->number);

        return ['id' => (string) ($body['refund_id'] ?? ''), 'status' => 'succeeded'];
    }

    public static function test(Integration $i): array
    {
        if (! $i->secret('api_token')) {
            return [false, 'API token not saved'];
        }
        if (! $i->secret('notification_token')) {
            return [false, 'Notification token not saved'];
        }
        try {
            $res = Http::timeout(15)->withToken($i->secret('api_token'))->acceptJson()->get(self::base($i).'/checkout/payment-types', ['country' => 'AE', 'currency' => 'AED']);
        } catch (\Throwable $e) {
            return [false, 'Could not reach Tamara: '.class_basename($e)];
        }

        return $res->successful() ? [true, null, 'Tamara · '.($i->mode === 'live' ? 'live' : 'sandbox')] : [false, 'API token refused (HTTP '.$res->status().')'];
    }
}
