<?php

namespace Tests\Feature;

use App\Enums\DriveMode;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\PaymentRequest;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Support\Accounts;
use App\Support\Payments\Gateways\Paymob;
use App\Support\Payments\Gateways\Tamara;
use App\Support\Payments\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/**
 * Brief 28 Oct-2, Phase 13: online payments. Every provider is mocked (Http::fake):
 * no real charge is ever made by these tests.
 */
class PaymentsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function connect(string $provider, array $secrets, array $config = [], string $mode = 'test', string $status = 'connected'): Integration
    {
        $i = Integration::for($provider);
        $i->fill(['enabled' => true, 'mode' => $mode, 'config' => $config, 'status' => $status]);
        $i->putSecrets($secrets, $mode);
        $i->save();

        return $i;
    }

    protected function stripe(): Integration
    {
        return $this->connect('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_test']);
    }

    protected function openBooking(): Booking
    {
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $b->syncBalance();

        return $b->fresh();
    }

    protected function stripeEvent(string $id, PaymentRequest $r, int $amountMinor, string $type = 'checkout.session.completed', string $paid = 'paid'): string
    {
        return json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => [
            'id' => $r->provider_ref, 'client_reference_id' => $r->attemptRef(), 'payment_status' => $paid,
            'amount_total' => $amountMinor, 'currency' => 'aed', 'payment_intent' => 'pi_123',
        ]]]);
    }

    protected function postStripe(string $raw, ?string $secret = 'whsec_test')
    {
        $t = time();

        return $this->call('POST', '/api/webhooks/payments/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, (string) $secret), 'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    /** Link → checkout at Stripe → signed webhook → recorded once. */
    protected function paidWithStripe(Booking $b): PaymentRequest
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);
        $r = Payments::request($b, 'balance', (float) $b->balance);
        $this->post(route('pay.start', ['token' => $r->token, 'provider' => 'stripe']))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $r->refresh();
        $this->postStripe($this->stripeEvent('evt_1', $r, $r->minor()))->assertOk();

        return $r->fresh();
    }

    public function test_stripe_link_checkout_and_signed_webhook_record_the_money_once(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $balance = (float) $b->balance;
        $this->assertGreaterThan(0, $balance);

        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $r = Payments::request($b, 'balance', $balance);
        $this->assertSame('open', $r->status);
        $this->assertMatchesRegularExpression('/^PR-\d{5}$/', $r->number);

        auth()->logout();
        $this->get($r->url())->assertOk()->assertSee('AED '.number_format($balance, 2))->assertSee('Pay by card');
        $this->post(route('pay.start', ['token' => $r->token, 'provider' => 'stripe']))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/checkout/sessions')
            && $req['line_items'][0]['price_data']['unit_amount'] == (int) round($balance * 100) && $req['client_reference_id'] === $r->fresh()->attemptRef());
        $r->refresh();
        $this->assertSame('processing', $r->status);

        // Coming back to the page without a webhook does not mark it paid.
        $this->assertSame(0, Transaction::where('booking_id', $b->id)->where('method', 'online')->count());

        $raw = $this->stripeEvent('evt_1', $r, $r->minor());
        $this->postStripe($raw)->assertOk();
        $this->postStripe($raw)->assertOk(); // the provider retries: still once

        $this->assertSame(1, Transaction::where('booking_id', $b->id)->where('method', 'online')->count());
        $t = Transaction::where('booking_id', $b->id)->where('method', 'online')->first();
        $this->assertSame(PaymentStatus::Paid, $t->status);
        $this->assertEqualsWithDelta($balance, (float) $t->amount, 0.001);
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame('pi_123', $r->fresh()->provider_payment_id);
        $this->assertEqualsWithDelta(0, (float) $b->fresh()->balance, 0.001);
        $this->assertSame(PaymentStatus::Paid, $b->fresh()->payment_status);
        $this->assertSame(2, (int) IntegrationEvent::where('provider', 'stripe')->where('external_id', 'evt_1')->value('attempts'));
        $this->get($r->url())->assertSee('Your payment of AED');
    }

    public function test_unsigned_or_forged_webhooks_record_nothing(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/x'])]);
        $r = Payments::request($b, 'balance', (float) $b->balance);
        Payments::start($r, 'stripe');
        auth()->logout();

        $raw = $this->stripeEvent('evt_forged', $r->fresh(), $r->minor());
        $this->postStripe($raw, 'whsec_wrong')->assertStatus(400);
        $this->call('POST', '/api/webhooks/payments/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(400);
        $this->postJson('/api/webhooks/payments/paymob', ['obj' => ['id' => 1]])->assertNotFound(); // not set up

        $this->assertSame(0, Transaction::where('method', 'online')->count());
        $this->assertNotSame('paid', $r->fresh()->status);
        $this->assertSame(2, IntegrationEvent::where('provider', 'stripe')->where('status', 'rejected')->count());
    }

    public function test_a_different_amount_is_held_for_checking_not_recorded(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/x'])]);
        $r = Payments::request($b, 'advance', 100);
        Payments::start($r, 'stripe');
        auth()->logout();

        $this->postStripe($this->stripeEvent('evt_2', $r->fresh(), 5000))->assertOk(); // AED 50 instead of 100
        $this->assertSame('review', $r->fresh()->status);
        $this->assertStringContainsString('was asked', $r->fresh()->last_error);
        $this->assertSame(0, Transaction::where('method', 'online')->count());
    }

    public function test_paymob_hmac_callback(): void
    {
        $this->connect('paymob', ['api_key' => 'k', 'secret_key' => 'are_sk_test_1', 'public_key' => 'are_pk_test_1', 'hmac_secret' => 'hm'], ['integration_ids' => '123, 456']);
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        Http::fake(['uae.paymob.com/v1/intention/' => Http::response(['id' => 'pi_test', 'client_secret' => 'cs_abc', 'intention_order_id' => 999])]);
        $r = Payments::request($b, 'advance', 250);
        $url = Payments::start($r, 'paymob');
        $this->assertSame('https://uae.paymob.com/unifiedcheckout/?publicKey=are_pk_test_1&clientSecret=cs_abc', $url);
        Http::assertSent(fn (HttpRequest $req) => $req['amount'] === 25000 && $req['payment_methods'] === [123, 456] && $req['special_reference'] === $r->fresh()->attemptRef());
        auth()->logout();

        $obj = ['id' => 555, 'amount_cents' => 25000, 'created_at' => '2026-11-02T09:00:00', 'currency' => 'AED', 'error_occured' => false, 'has_parent_transaction' => false,
            'integration_id' => 123, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true,
            'is_voided' => false, 'order' => ['id' => 999, 'merchant_order_id' => $r->fresh()->attemptRef()], 'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'], 'success' => true];
        $this->postJson('/api/webhooks/payments/paymob?hmac=bad', ['type' => 'TRANSACTION', 'obj' => $obj])->assertStatus(400);
        $this->assertSame(0, Transaction::where('method', 'online')->count());

        $this->postJson('/api/webhooks/payments/paymob?hmac='.Paymob::hmacFor($obj, 'hm'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame('555', $r->fresh()->provider_payment_id);
        $this->assertEqualsWithDelta(250, (float) Transaction::where('method', 'online')->sum('amount'), 0.001);
    }

    public function test_tabby_is_read_back_and_captured_before_recording(): void
    {
        $this->connect('tabby', ['public_key' => 'pk_test', 'secret_key' => 'sk_test', 'webhook_secret' => 'tabby-secret'], ['merchant_code' => 'RPAE', 'webhook_header' => 'X-RP-Webhook']);
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $r = Payments::request($b, 'advance', 500);
        Http::fake([
            'api.tabby.ai/api/v2/checkout' => Http::response(['id' => 'sess', 'status' => 'created', 'payment' => ['id' => 'pay_1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/x']]]]]),
            'api.tabby.ai/api/v2/payments/pay_1/captures' => Http::response(['id' => 'pay_1', 'status' => 'CLOSED', 'amount' => '500.00', 'currency' => 'AED', 'order' => ['reference_id' => $r->number.'-1'], 'captures' => [['amount' => '500.00']]]),
            'api.tabby.ai/api/v2/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'AUTHORIZED', 'amount' => '500.00', 'currency' => 'AED', 'order' => ['reference_id' => $r->number.'-1'], 'captures' => []]),
        ]);
        $this->assertSame('https://checkout.tabby.ai/x', Payments::start($r, 'tabby'));
        auth()->logout();

        $this->postJson('/api/webhooks/payments/tabby', ['id' => 'pay_1', 'status' => 'authorized'], ['X-RP-Webhook' => 'nope'])->assertStatus(400);
        $this->assertSame(0, Transaction::where('method', 'online')->count());

        $this->postJson('/api/webhooks/payments/tabby', ['id' => 'pay_1', 'status' => 'authorized'], ['X-RP-Webhook' => 'tabby-secret'])->assertOk();
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/captures') && $req['amount'] === '500.00');
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertEqualsWithDelta(500, (float) Transaction::where('method', 'online')->sum('amount'), 0.001);
    }

    public function test_tamara_jwt_then_authorise_and_capture(): void
    {
        $this->connect('tamara', ['api_token' => 'tok', 'notification_token' => 'notify-secret']);
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $r = Payments::request($b, 'advance', 500);
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response(['order_id' => 'ord_1', 'checkout_id' => 'c1', 'checkout_url' => 'https://checkout-sandbox.tamara.co/x']),
            'api-sandbox.tamara.co/orders/ord_1/authorise' => Http::response(['status' => 'authorised']),
            'api-sandbox.tamara.co/payments/capture' => Http::response(['capture_id' => 'cap1', 'status' => 'fully_captured']),
            'api-sandbox.tamara.co/orders/ord_1' => Http::response(['order_id' => 'ord_1', 'order_reference_id' => $r->number.'-1', 'status' => 'approved', 'total_amount' => ['amount' => 500, 'currency' => 'AED']]),
        ]);
        Payments::start($r, 'tamara');
        auth()->logout();

        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $jwt = fn (string $secret) => ($h = $b64(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$b64(['iat' => time(), 'exp' => time() + 600]))
            .'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $h, $secret, true)), '+/', '-_'), '=');
        $this->assertTrue(Tamara::jwtValid($jwt('notify-secret'), 'notify-secret'));

        $this->postJson('/api/webhooks/payments/tamara?tamaraToken='.$jwt('other'), ['order_id' => 'ord_1', 'order_status' => 'approved'])->assertStatus(400);
        $this->postJson('/api/webhooks/payments/tamara?tamaraToken='.$jwt('notify-secret'), ['order_id' => 'ord_1', 'order_status' => 'approved'])->assertOk();
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/authorise'));
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/payments/capture') && $req['order_id'] === 'ord_1');
        $this->assertSame('paid', $r->fresh()->status);
    }

    public function test_paypal_needs_a_currency_and_is_captured_on_return(): void
    {
        $i = $this->connect('paypal', ['client_id' => 'cid', 'client_secret' => 'cs']);
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $r = Payments::request($b, 'advance', 367.25);
        $this->assertArrayNotHasKey('paypal', Payments::choices($r)); // no currency / rate set yet

        $i->update(['config' => ['currency' => 'USD', 'aed_rate' => '3.6725']]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21', 'expires_in' => 3000]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1/capture' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED', 'custom_id' => $r->number.'-1', 'amount' => ['currency_code' => 'USD', 'value' => '100.00']]]]]]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER1']]]),
        ]);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER1', Payments::start($r, 'paypal'));
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/v2/checkout/orders') && $req['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '100.00']);
        auth()->logout();

        $this->get(route('pay.return', ['token' => $r->token, 'provider' => 'paypal']).'?token=OTHER')->assertRedirect(route('pay.show', $r->token));
        $this->assertSame('processing', $r->fresh()->status); // someone else's order id: nothing captured

        $this->get(route('pay.return', ['token' => $r->token, 'provider' => 'paypal']).'?token=ORDER1&PayerID=X')->assertRedirect(route('pay.show', $r->token));
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame('CAP1', $r->fresh()->provider_payment_id);
        $this->assertEqualsWithDelta(367.25, (float) Transaction::where('method', 'online')->sum('amount'), 0.001);
    }

    public function test_only_connected_gateways_are_offered_and_limits_are_kept(): void
    {
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        try {
            Payments::request($b, 'balance', 100);
            $this->fail('No gateway: must refuse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No payment gateway is connected', collect($e->errors())->flatten()->first());
        }

        $this->connect('tabby', ['public_key' => 'p', 'secret_key' => 's', 'webhook_secret' => 'w'], ['merchant_code' => 'X', 'min_amount' => 200], status: 'configured');
        $this->assertSame([], Payments::connected()); // saved but never tested: not offered

        $this->stripe();
        Integration::for('tabby')->update(['status' => 'connected']);
        $small = Payments::request($b, 'advance', 100);
        $this->assertSame(['stripe'], array_keys(Payments::choices($small))); // below Tabby's minimum

        try {
            Payments::request($b, 'balance', (float) $b->balance); // the open AED 100 link counts
            $this->fail('Over the limit: must refuse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('At most AED', collect($e->errors())->flatten()->first());
        }

        Payments::cancel($small);
        $this->post(route('pay.start', ['token' => $small->token, 'provider' => 'stripe']))->assertRedirect(route('pay.show', $small->token));
        $this->get(route('pay.show', $small->token))->assertSee('This payment link is closed');
        $this->get('/pay/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_refund_is_finance_only_and_splits_a_part_refund(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $r = $this->paidWithStripe($b);
        $paid = (float) $r->amount;

        $this->actingAs($this->user('Sales'));
        try {
            Payments::refund($r, 100, 'Changed dates');
            $this->fail('Sales must not refund');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->user('Finance'));
        Payments::refund($r->fresh(), 100, 'One day less', auth()->user());
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/v1/refunds') && (int) $req['amount'] === 10000 && $req['payment_intent'] === 'pi_123');

        $r->refresh();
        $this->assertSame('partly_refunded', $r->status);
        $this->assertEqualsWithDelta(100, (float) $r->refunded_amount, 0.001);
        $this->assertEqualsWithDelta($paid - 100, (float) $r->transaction->fresh()->amount, 0.001);
        $this->assertSame(1, Transaction::where('booking_id', $b->id)->where('status', PaymentStatus::Refunded)->where('amount', 100)->count());
        $this->assertEqualsWithDelta(100, (float) $b->fresh()->balance, 0.001);

        try {
            Payments::refund($r, $paid, 'Too much');
            $this->fail('Over-refund must be refused');
        } catch (ValidationException) {
        }
    }

    public function test_customer_pays_own_balance_from_the_account(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $b = $this->openBooking();
        $other = $this->openBooking();
        $account = Accounts::customerAccount($b->customer, 'payer@example.com');
        $this->settings(['customer_sees_payments' => true]);

        $this->actingAs($account);
        $this->get('/account/bookings/'.$b->id)->assertOk()->assertSee('Pay the balance online');
        $this->get('/account/bookings/'.$other->id)->assertNotFound();

        $link = Payments::balanceLink($b->fresh());
        $this->assertEqualsWithDelta((float) $b->balance, (float) $link->amount, 0.001);
        $this->assertTrue(Payments::balanceLink($b->fresh())->is($link)); // re-used, not a second link
    }

    public function test_quotation_advance_paid_online_moves_onto_the_booking(): void
    {
        $this->stripe();
        $this->actingAs($this->user('Sales'));
        $car = $this->car();
        $q = Quotation::create(['customer_id' => $this->customer()->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-20 10:00', 'end_at' => '2026-11-22 10:00', 'valid_until' => '2026-11-08', 'advance_required' => 500]);
        try {
            Payments::quotationLink($q);
            $this->fail('Not accepted yet');
        } catch (ValidationException) {
        }
        $q->update(['status' => QuotationStatus::Accepted, 'accepted_at' => now(), 'sent_at' => now()]);
        $this->assertSame(500.0, Payments::quotationAmount($q->fresh()));
        auth()->logout();
        $this->get($q->fresh()->publicUrl())->assertSee('Pay the advance online');

        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_q', 'url' => 'https://checkout.stripe.com/q'])]);
        $this->post(route('quotations.public.pay', ['token' => $q->public_token]))->assertRedirect();
        $r = PaymentRequest::where('quotation_id', $q->id)->firstOrFail();
        $this->assertSame('advance', $r->purpose);
        Payments::start($r, 'stripe');
        $this->postStripe($this->stripeEvent('evt_q', $r->fresh(), 50000))->assertOk();
        $t = Transaction::where('method', 'online')->firstOrFail();
        $this->assertNull($t->booking_id);
        $this->assertNull(Payments::quotationAmount($q->fresh())); // advance done

        $this->actingAs($this->user('Manager'));
        $b = $this->booking($car, '2026-11-20 10:00', '2026-11-22 10:00', ['customer_id' => $q->customer_id, 'quotation_id' => $q->id]);
        $q->update(['booking_id' => $b->id]);
        $this->assertSame(1, Payments::moveToBooking($q, $b));
        $this->assertSame($b->id, $t->fresh()->booking_id);
        $this->assertEqualsWithDelta(500, (float) $b->fresh()->paid_amount, 0.001);
    }
}
