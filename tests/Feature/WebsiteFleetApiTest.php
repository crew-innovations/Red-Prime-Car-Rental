<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\Pipeline;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\PriceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 6.2: published cars and yachts, server price estimates and availability for the website. */
class WebsiteFleetApiTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        RateLimiter::clear('api-booking-requests');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_published_cars_and_yachts_and_no_private_fields(): void
    {
        $car = $this->car(['name' => 'Lamborghini Urus', 'is_published' => true, 'registration_number' => 'DXB-SECRET-1', 'vin' => 'VINSECRET', 'notes' => 'INTERNAL', 'partner_name' => 'Partner LLC', 'insurance_policy_no' => 'POL-999']);
        $this->car(['name' => 'Hidden Car', 'is_published' => false]);
        $yacht = $this->yacht(['name' => 'Ocean Pearl', 'is_published' => true, 'license_number' => 'LIC-SECRET', 'notes' => 'INTERNAL']);

        $cars = $this->getJson('/api/v1/cars')->assertOk()->json('data');
        $this->assertSame([$car->slug], array_column($cars, 'slug'));
        $detail = $this->getJson('/api/v1/cars/'.$car->slug)->assertOk()->json('data');
        $this->assertSame(1000.0, (float) $detail['prices']['daily']);
        $this->assertTrue($detail['self_drive']);
        $json = json_encode($detail);
        foreach (['DXB-SECRET-1', 'VINSECRET', 'INTERNAL', 'Partner LLC', 'POL-999', 'partner_commission', 'status'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->getJson('/api/v1/cars/hidden-car')->assertNotFound();

        $y = $this->getJson('/api/v1/yachts/'.$yacht->slug)->assertOk()->json('data');
        $this->assertSame(12, $y['guests']);
        $this->assertStringNotContainsString('LIC-SECRET', json_encode($y));
        $this->assertCount(1, $this->getJson('/api/v1/yachts?guests=10')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/yachts?guests=20')->json('data'));
    }

    public function test_price_estimate_uses_the_crm_engine_and_reports_availability_without_details(): void
    {
        $car = $this->car(['is_published' => true]);
        PriceRule::create(['name' => 'Internal peak name', 'service_type' => 'car', 'applies_to' => 'item', 'item_id' => $car->id,
            'unit' => 'day', 'method' => 'set', 'amount' => 1200, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);

        $res = $this->postJson('/api/v1/price-estimate', [
            'product_type' => 'car', 'product_slug' => $car->slug, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'drive_mode' => 'self_drive',
            'total' => 1, // ignored
        ])->assertOk();
        $res->assertJsonPath('data.available', true)
            ->assertJsonPath('data.price.total', 2400)
            ->assertJsonPath('data.security_deposit', 2000);
        $this->assertStringNotContainsString('Internal peak name', $res->getContent(), 'special rate names stay internal');

        // Booked for those dates: not available, no booking reference or customer shown.
        $this->booking($car, '2026-11-11 10:00', '2026-11-13 10:00', ['status' => BookingStatus::Confirmed]);
        $res = $this->postJson('/api/v1/price-estimate', ['product_type' => 'car', 'product_slug' => $car->slug, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00'])->assertOk();
        $res->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'Not available for these dates.');
        $this->assertStringNotContainsString('BK-', $res->getContent());
        $this->assertStringNotContainsString('Ahmed', $res->getContent());
        $this->assertSame(1, Booking::count(), 'an estimate never books or holds');

        // Unpublished or unknown products are refused; past dates are refused.
        $hidden = $this->car(['is_published' => false]);
        $this->postJson('/api/v1/price-estimate', ['product_type' => 'car', 'product_slug' => $hidden->slug, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00'])->assertUnprocessable()->assertJsonValidationErrors('product_slug');
        $this->postJson('/api/v1/price-estimate', ['product_type' => 'car', 'product_slug' => $car->slug, 'start_at' => '2026-10-01 10:00', 'end_at' => '2026-10-02 10:00'])->assertUnprocessable()->assertJsonValidationErrors('start_at');
    }

    public function test_yacht_charter_estimate_and_request(): void
    {
        $yacht = $this->yacht(['is_published' => true, 'minimum_hours' => 2]);
        $this->postJson('/api/v1/price-estimate', ['product_type' => 'yacht', 'product_slug' => $yacht->slug, 'start_at' => '2026-11-10 16:00', 'end_at' => '2026-11-10 19:00', 'guests' => 8])
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.price.total', 2700);

        $res = $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'yacht', 'product_slug' => $yacht->slug, 'request_type' => 'booking_request',
            'date' => '2026-11-10', 'start_at' => '2026-11-10 16:00', 'end_at' => '2026-11-10 19:00', 'guests' => 8,
            'name' => 'Charter Guest', 'phone' => '+971 55 444 5566',
        ])->assertCreated();
        $res->assertJsonPath('data.price_estimate.amount', 2700)->assertJsonPath('data.confirmed', false);

        $lead = Lead::firstOrFail();
        $this->assertSame(ServiceType::Yacht, $lead->service_type);
        $this->assertSame(Pipeline::Yacht, $lead->pipeline);
        $this->assertStringContainsString('From 2026-11-10 16:00 to 2026-11-10 19:00', $lead->message);
        $this->assertSame(0, Booking::count());
    }

    public function test_staff_hints_are_not_shown_to_customers(): void
    {
        $this->assertSame(['Shorter than this car\'s minimum rental (3 days).'], \App\Http\Controllers\Api\V1\PriceEstimateController::publicWarnings([
            'Shorter than this car\'s minimum rental (3 days).',
            'No delivery fee on the price list (set one on the car or under Pricing › Extras & fees). Delivery is free unless you add a line.',
        ]));
    }
}
