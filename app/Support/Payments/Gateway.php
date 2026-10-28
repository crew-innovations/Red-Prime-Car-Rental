<?php

namespace App\Support\Payments;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\PaymentRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * One payment provider. Every method talks to the provider's own API from the
 * server; nothing the browser sends is trusted as proof of payment.
 *
 * A normalised result (webhook() / confirm()) is an array:
 *   status       paid | failed | expired | pending | ignored
 *   ref          our attempt reference (PR-00001-2), when the provider sends it back
 *   provider_ref checkout / order id
 *   payment_id   payment / capture id (used for refunds)
 *   amount_minor amount actually paid, in minor units of `currency`
 *   currency     ISO code
 *   event_id     unique id of this notification (idempotency)
 *   event        provider event name
 */
abstract class Gateway
{
    public function __construct(protected Integration $integration)
    {
    }

    abstract public static function key(): string;

    /** Starts a checkout for one attempt. Returns ['url' => …, 'provider_ref' => …, 'meta' => [...]]. */
    abstract public function checkout(PaymentRequest $r, string $returnUrl, string $cancelUrl): array;

    /** Verifies and reads a webhook. Throws InvalidWebhook when it can't be trusted. */
    abstract public function webhook(Request $request): array;

    /** Customer came back from checkout: ask the provider for the real status. Null = wait for the webhook. */
    public function confirm(PaymentRequest $r, Request $request): ?array
    {
        return null;
    }

    /** Returns ['id' => refund id, 'status' => succeeded | pending]. Throws GatewayError when refused. */
    abstract public function refund(PaymentRequest $r, float $amount, string $reason): array;

    /** Settings › Integrations › Test connection: [ok, error, account label]. */
    abstract public static function test(Integration $i): array;

    /** Can this gateway take this request (amount limits, currency set up…)? Null = yes, otherwise the reason. */
    public function refuses(PaymentRequest $r): ?string
    {
        $min = $this->integration->config('min_amount');
        $max = $this->integration->config('max_amount');
        if (filled($min) && (float) $r->amount < (float) $min) {
            return 'Below the '.number_format((float) $min, 2).' minimum';
        }
        if (filled($max) && (float) $r->amount > (float) $max) {
            return 'Above the '.number_format((float) $max, 2).' maximum';
        }

        return null;
    }

    /** What the provider must report as paid: [minor units, currency]. */
    public function expected(PaymentRequest $r): array
    {
        return [$r->minor(), strtoupper($r->currency ?: 'AED')];
    }

    public function label(): string
    {
        return $this->integration->label();
    }

    public function mode(): string
    {
        return $this->integration->mode === 'live' ? 'live' : 'test';
    }

    protected function secret(string $name): string
    {
        $v = $this->integration->secret($name);
        if (! $v) {
            throw new GatewayError($this->label().': the '.str_replace('_', ' ', $name).' is not saved.');
        }

        return $v;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(20)->connectTimeout(8)->acceptJson();
    }

    /** Throws a readable GatewayError for a failed response and logs the call (no payload, no keys). */
    protected function ok(Response $res, string $what, ?string $ref = null): array
    {
        $body = (array) $res->json();
        if ($res->failed()) {
            $msg = $this->errorText($body) ?: 'HTTP '.$res->status();
            IntegrationEvent::log(static::key(), 'out', 'failed', $what, null, ['ref' => $ref, 'http' => $res->status()], $msg);
            throw new GatewayError($this->label().': '.$what.' refused · '.$msg);
        }
        IntegrationEvent::log(static::key(), 'out', 'ok', $what, null, ['ref' => $ref, 'http' => $res->status()]);

        return $body;
    }

    protected function errorText(array $body): ?string
    {
        $m = data_get($body, 'error.message') ?? data_get($body, 'message') ?? data_get($body, 'detail') ?? data_get($body, 'error_description')
            ?? data_get($body, 'errors.0.message') ?? data_get($body, 'details.0.description') ?? data_get($body, 'error');

        return is_string($m) ? mb_substr($m, 0, 200) : null;
    }

    protected static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** Splits a name into first / last for providers that need both. */
    protected static function names(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2) ?: [];

        return [$parts[0] ?? 'Customer', $parts[1] ?? ($parts[0] ?? 'Customer')];
    }
}
