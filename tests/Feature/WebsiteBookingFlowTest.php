<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Website Book now is a booking waiting for payment, not a lead waiting for a quotation. */
class WebsiteBookingFlowTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected bool $confirmNeedsDetails = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        RateLimiter::clear('api-booking-requests');
        RateLimiter::clear('api-enquiries');
    }

    public function test_an_enquiry_is_only_a_lead(): void
    {
        $this->postJson('/api/v1/enquiries', [
            'service' => 'car', 'name' => 'Nora', 'phone' => '+971 55 100 2000', 'message' => 'Price for a week',
        ])->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertSame('new', $lead->stage);
        $this->assertSame(0, Booking::count());
        $this->assertTrue(Lead::withoutLiveBooking()->whereKey($lead->id)->exists());
    }

    public function test_book_now_holds_the_car_and_leaves_the_lead_off_the_quote_list(): void
    {
        $car = $this->car(['is_published' => true, 'slug' => 'patrol-book']);
        $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'car', 'product_slug' => $car->slug, 'request_type' => 'booking_request',
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00',
            'name' => 'Sara', 'phone' => '+971 55 200 3000',
        ])->assertCreated();
        $this->assertSame(0, Booking::count());

        $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'car', 'product_slug' => $car->slug, 'request_type' => 'booking',
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'drive_mode' => 'self_drive',
            'name' => 'Sara', 'phone' => '+971 55 200 3000', 'email' => 'sara@example.com',
        ])->assertCreated();

        $this->assertSame(1, Lead::count());
        $lead = Lead::firstOrFail();
        $booking = Booking::firstOrFail();
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame('payment_pending', $lead->fresh()->stage);
        $this->assertSame('Waiting for payment', $booking->statusLabel());
        $this->assertFalse(Lead::withoutLiveBooking()->whereKey($lead->id)->exists());
        $this->assertTrue($lead->hasLiveBooking());
    }

    public function test_a_paid_booking_with_missing_documents_stays_unconfirmed(): void
    {
        $car = $this->car(['is_published' => true, 'slug' => 'patrol-pay']);
        $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'car', 'product_slug' => $car->slug, 'request_type' => 'booking',
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'drive_mode' => 'self_drive',
            'name' => 'Omar', 'phone' => '+971 55 300 4000', 'email' => 'omar@example.com',
        ])->assertCreated();

        $booking = Booking::firstOrFail();
        $booking->recordPayment((float) $booking->fresh()->total, 'card');
        $booking->refresh();

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame('Paid, documents pending', $booking->statusLabel());
    }

    public function test_an_unpaid_hold_returns_the_lead_for_follow_up(): void
    {
        $car = $this->car(['is_published' => true, 'slug' => 'patrol-hold']);
        $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'car', 'product_slug' => $car->slug, 'request_type' => 'booking',
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'drive_mode' => 'self_drive',
            'name' => 'Lina', 'phone' => '+971 55 400 5000', 'email' => 'lina@example.com',
        ])->assertCreated();

        $booking = Booking::firstOrFail();
        $booking->forceFill(['hold_until' => now()->subHour()])->saveQuietly();

        $this->artisan('crm:release-website-holds')->assertSuccessful();

        $booking->refresh();
        $lead = $booking->lead->fresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('contacted', $lead->stage);
        $this->assertNotNull($lead->next_follow_up_at);
        $this->assertTrue($lead->activities()->where('body', 'Website hold ended, not paid.')->exists());
        $this->assertTrue(Lead::withoutLiveBooking()->whereKey($lead->id)->exists());
    }

    public function test_a_limousine_request_without_a_confirmed_price_stays_an_open_lead(): void
    {
        \App\Models\TransferService::create([
            'name' => 'Point to point', 'slug' => 'point-to-point', 'journey_type' => 'one_way',
            'needs_destination' => true, 'is_airport' => false, 'is_active' => true, 'is_published' => true,
        ]);
        VehicleCategory::create(['name' => 'Sedan', 'is_active' => true, 'is_published' => true, 'passengers' => 3]);
        $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'transfer', 'product_slug' => 'sedan', 'request_type' => 'booking',
            'pickup' => 'DXB Terminal 3', 'dropoff' => 'Downtown Dubai', 'start_at' => '2026-11-10 14:00',
            'name' => 'Hadi', 'phone' => '+971 55 500 6000', 'email' => 'hadi@example.com',
        ])->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertSame('new', $lead->stage);
        $this->assertTrue(Lead::withoutLiveBooking()->whereKey($lead->id)->exists());
        $this->assertFalse($lead->hasLiveBooking());
    }
}
