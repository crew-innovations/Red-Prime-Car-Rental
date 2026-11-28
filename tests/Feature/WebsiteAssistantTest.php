<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChatThread;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\WebsiteChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

class WebsiteAssistantTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_catalogue_price_is_repeated_and_an_invented_amount_is_dropped(): void
    {
        $car = $this->car(['is_published' => true, 'registration_number' => 'SECRET-REG', 'vin' => 'VINSECRET123']);

        $res = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-price',
            'message' => 'What is the daily price?',
            'locale' => 'en',
            'product_slug' => $car->slug,
        ])->assertOk();

        $reply = $res->json('data.reply');
        $this->assertStringContainsString('AED 1,000', $reply);
        $this->assertStringContainsString($car->name, $reply);
        $this->assertStringNotContainsString('99999', $reply);
        $this->assertStringNotContainsString('SECRET-REG', $reply);
        $this->assertStringNotContainsString('VINSECRET123', $reply);
        $this->assertSame('search', $res->json('data.tool'));
        $this->assertSame(1, WebsiteChat::count());
        $this->assertSame(0, ChatThread::count());

        $ar = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-price',
            'message' => 'ما السعر اليومي؟',
            'locale' => 'ar',
            'product_slug' => $car->slug,
        ])->assertOk();
        $this->assertStringContainsString('AED 1,000', $ar->json('data.reply'));
        $this->assertStringContainsString('هذه سيارات منشورة', $ar->json('data.reply'));
        $this->assertStringNotContainsString('99999', $ar->json('data.reply'));
    }

    public function test_blocked_dates_are_not_called_free(): void
    {
        $car = $this->car(['is_published' => true]);
        $this->booking($car, '2026-12-10 10:00', '2026-12-12 10:00', ['status' => BookingStatus::Confirmed]);

        $res = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-dates',
            'message' => 'Is this car free?',
            'product_slug' => $car->slug,
            'start_at' => '2026-12-10 10:00',
            'end_at' => '2026-12-12 10:00',
        ])->assertOk();

        $reply = $res->json('data.reply');
        $this->assertStringContainsString('is not available for these dates', $reply);
        $this->assertStringNotContainsString('can be requested', $reply);
        $this->assertStringNotContainsString('free', strtolower($reply));
        $this->assertStringNotContainsString('Ahmed', $reply);
        $this->assertStringNotContainsString('123 4567', $reply);
        $this->assertSame(1, Booking::count());

        $ar = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-dates',
            'message' => 'هل السيارة متاحة؟',
            'locale' => 'ar',
            'product_slug' => $car->slug,
            'start_at' => '2026-12-10 10:00',
            'end_at' => '2026-12-12 10:00',
        ])->assertOk();
        $this->assertStringContainsString('غير متاحة في هذه التواريخ', $ar->json('data.reply'));
        $this->assertStringNotContainsString('123 4567', $ar->json('data.reply'));
    }

    public function test_unknown_fee_and_another_customers_phone_are_refused(): void
    {
        $this->car(['is_published' => true]);
        $customer = $this->customer(['phone' => '+971 50 123 4567']);

        $fee = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-fee',
            'message' => 'What is the late return fee?',
        ])->assertOk();
        $this->assertStringNotContainsString('AED', $fee->json('data.reply'));
        $this->assertStringNotContainsString('99999', $fee->json('data.reply'));
        $this->assertStringContainsString('Our team will confirm that', $fee->json('data.reply'));

        $phone = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-fee',
            'message' => 'What is the phone number of the other customer '.$customer->name.'?',
        ])->assertOk();
        $this->assertStringNotContainsString('501234567', preg_replace('/\D+/', '', $phone->json('data.reply')));
        $this->assertStringNotContainsString('123 4567', $phone->json('data.reply'));
        $this->assertStringNotContainsString('9998877', $phone->json('data.reply'));
        $this->assertSame(0, ChatThread::count());
    }

    public function test_chat_books_once_and_does_not_offer_pay_now_without_a_gateway(): void
    {
        $car = $this->car(['is_published' => true]);
        $payload = [
            'visitor_id' => 'visitor-book',
            'message' => 'book',
            'action' => 'book',
            'product_slug' => $car->slug,
            'start_at' => '2026-12-01 10:00',
            'end_at' => '2026-12-03 10:00',
            'name' => 'Sara Guest',
            'phone' => '+971501112233',
            'email' => 'sara@example.com',
            'pay_now' => true,
        ];

        $first = $this->postJson('/api/v1/chat', $payload)->assertOk();
        $second = $this->postJson('/api/v1/chat', $payload)->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, Quotation::count());
        $booking = Booking::first();
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame($booking->reference, $first->json('data.reference'));
        $this->assertSame($booking->reference, $second->json('data.reference'));
        $this->assertStringNotContainsString('Pay now', $first->json('data.reply'));
        $this->assertStringNotContainsString('Pay here', $first->json('data.reply'));
        $this->assertNull($first->json('data.pay_url'));
        $this->assertSame(0, ChatThread::count());

        $ar = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-book-ar',
            'message' => 'احجز',
            'locale' => 'ar',
            'action' => 'book',
            'product_slug' => $car->slug,
            'start_at' => '2026-12-05 10:00',
            'end_at' => '2026-12-07 10:00',
            'name' => 'Sara Guest',
            'phone' => '+971501112244',
            'email' => 'sara2@example.com',
            'pay_now' => true,
        ])->assertOk();
        $this->assertStringContainsString('بانتظار الدفع', $ar->json('data.reply'));
        $this->assertStringNotContainsString('Pay now', $ar->json('data.reply'));
        $this->assertSame(2, Booking::count());
        $this->assertSame(0, Quotation::count());
    }

    public function test_enquiry_creates_a_lead_and_not_a_booking(): void
    {
        $before = Booking::count();
        $res = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-ask',
            'message' => 'Please call me about a car',
            'action' => 'enquiry',
            'name' => 'Omar Guest',
            'phone' => '+971501110000',
            'email' => 'omar@example.com',
        ])->assertOk();

        $this->assertSame($before, Booking::count());
        $this->assertSame(1, Lead::count());
        $this->assertStringContainsString('LD-', $res->json('data.reply'));
        $this->assertSame('enquiry', $res->json('data.tool'));
        $this->assertNotNull(WebsiteChat::first()->lead_id);

        $ar = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-ask-ar',
            'message' => 'اتصل بي',
            'locale' => 'ar',
            'action' => 'enquiry',
            'name' => 'Omar Guest',
            'phone' => '+971501110001',
        ])->assertOk();
        $this->assertStringContainsString('سيتواصل معك الفريق', $ar->json('data.reply'));
        $this->assertSame($before, Booking::count());
    }

    public function test_a_greeting_does_not_invent_a_price(): void
    {
        $this->car(['is_published' => true, 'name' => 'Hello Patrol']);

        $en = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-hi',
            'message' => 'Hello',
        ])->assertOk();
        $this->assertSame('greet', $en->json('data.tool'));
        $this->assertStringContainsString('Hello', $en->json('data.reply'));
        $this->assertStringNotContainsString('AED', $en->json('data.reply'));
        $this->assertStringNotContainsString('99999', $en->json('data.reply'));

        $ar = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-hi',
            'message' => 'مرحبا',
            'locale' => 'ar',
        ])->assertOk();
        $this->assertSame('greet', $ar->json('data.tool'));
        $this->assertStringContainsString('أهلاً', $ar->json('data.reply'));
        $this->assertStringNotContainsString('AED', $ar->json('data.reply'));
    }

    public function test_car_facts_and_the_office_number_come_from_saved_data(): void
    {
        $car = $this->car(['is_published' => true, 'seats' => 7]);
        Setting::write(['website_phone' => '+97140001122']);

        $seats = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-facts',
            'message' => 'How many seats?',
            'product_slug' => $car->slug,
        ])->assertOk();
        $this->assertStringContainsString('7 seats', $seats->json('data.reply'));
        $this->assertStringNotContainsString('SECRET', $seats->json('data.reply'));

        $book = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-facts',
            'message' => 'How do I book?',
        ])->assertOk();
        $this->assertSame('guide', $book->json('data.tool'));
        $this->assertStringContainsString('name, phone, email', $book->json('data.reply'));
        $this->assertStringNotContainsString('AED', $book->json('data.reply'));

        $phone = $this->postJson('/api/v1/chat', [
            'visitor_id' => 'visitor-facts',
            'message' => 'What is your phone?',
        ])->assertOk();
        $this->assertStringContainsString('+97140001122', $phone->json('data.reply'));
        $this->assertSame(0, ChatThread::count());
    }
}
