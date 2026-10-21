<?php

namespace Tests\Feature;

use App\Enums\Pipeline;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Trip;
use App\Support\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Public website API: published catalogue only, and safe enquiries / booking requests. */
class WebsiteApiTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        RateLimiter::clear('api-booking-requests');
    }

    protected function package(array $attrs = []): Package
    {
        $cat = PackageCategory::firstOrCreate(['slug' => 'desert'], ['name' => 'Desert']);

        return Package::create($attrs + [
            'name' => 'Liwa Safari '.\Illuminate\Support\Str::random(3), 'package_category_id' => $cat->id,
            'duration_days' => 3, 'pricing_mode' => 'per_person', 'price' => 1000,
            'booking_mode' => 'departures', 'default_capacity' => 4, 'is_published' => true,
            'notes' => 'INTERNAL NOTE', 'itinerary' => [['title' => 'Arrive'], ['title' => 'Dunes'], ['title' => 'Return']],
        ]);
    }

    public function test_only_published_packages_are_listed_and_shown(): void
    {
        $live = $this->package();
        $hidden = $this->package(['is_published' => false]);
        $paused = $this->package(['status' => 'paused']);

        $list = $this->getJson('/api/v1/packages')->assertOk()->json('data');
        $this->assertSame([$live->slug], array_column($list, 'slug'));

        $this->getJson('/api/v1/packages/'.$hidden->slug)->assertNotFound();
        $this->getJson('/api/v1/packages/'.$paused->slug)->assertNotFound();

        $detail = $this->getJson('/api/v1/packages/'.$live->slug)->assertOk()->json('data');
        $this->assertSame([1, 2, 3], array_column($detail['itinerary'], 'day'));
        $this->assertSame(['Arrive', 'Dunes', 'Return'], array_column($detail['itinerary'], 'title'));
        $this->assertStringNotContainsString('INTERNAL NOTE', json_encode($detail), 'internal notes are never public');
        $this->assertArrayNotHasKey('notes', $detail);

        $this->getJson('/api/v1/package-categories')->assertOk()
            ->assertJsonPath('data.0.slug', 'desert')
            ->assertJsonPath('data.0.packages_count', 1);
    }

    public function test_yacht_trips_are_listed_without_any_yacht_or_operator_data(): void
    {
        $trip = $this->trip(null, ['is_published' => true, 'operator_name' => 'Secret Operator LLC', 'notes' => 'INTERNAL']);
        $this->tripDate($trip, today()->addDays(2), ['available_slots' => 8]);
        $this->trip(null, ['name' => 'Hidden', 'is_published' => false]);

        $list = $this->getJson('/api/v1/yacht-trips')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $detail = $this->getJson('/api/v1/yacht-trips/'.$trip->slug)->assertOk()->json('data');
        $this->assertSame(8, $detail['departures'][0]['seats_left']);
        $this->assertStringNotContainsString('Secret Operator', json_encode($detail));
        $this->assertStringNotContainsString('INTERNAL', json_encode($detail));
    }

    public function test_booking_request_creates_a_lead_never_a_booking_with_server_side_price(): void
    {
        $p = $this->package(['price_tiers' => [['min_guests' => 3, 'price' => 900]]]);
        $d = $p->departures()->create(['start_date' => today()->addDays(10)]);

        $res = $this->postJson('/api/v1/booking-requests', [
            'product_type' => 'package', 'product_slug' => $p->slug, 'departure_id' => $d->id,
            'request_type' => 'booking_request', 'guests' => 3,
            'name' => 'Web Customer', 'phone' => '+971 55 111 2233', 'email' => 'web@example.com',
            'price' => 1, 'total' => 1, 'status' => 'confirmed', // ignored
        ])->assertCreated();

        $res->assertJsonPath('data.confirmed', false)
            ->assertJsonPath('data.request_type', 'booking_request')
            ->assertJsonPath('data.price_estimate.amount', 2700);

        $lead = Lead::firstOrFail();
        $this->assertSame(ServiceType::Package, $lead->service_type);
        $this->assertSame(Pipeline::Yacht, $lead->pipeline);
        $this->assertSame('new', $lead->stage);
        $this->assertSame($d->id, $lead->package_departure_id);
        $this->assertSame('Website', $lead->leadSource->name);
        $this->assertSame(0, Booking::count(), 'a website request never books');
        $this->assertSame(0, $d->bookedGuests(), 'a request does not take places');
    }

    public function test_repeated_and_duplicate_requests_return_the_first_lead(): void
    {
        $trip = $this->trip(null, ['is_published' => true]);
        $date = $this->tripDate($trip, today()->addDays(4));
        $body = ['product_type' => 'yacht_trip', 'product_slug' => $trip->slug, 'departure_id' => $date->id, 'guests' => 2, 'name' => 'A', 'phone' => '0501112233'];

        $first = $this->postJson('/api/v1/booking-requests', $body + ['idempotency_key' => 'abc-123'])->assertCreated()->json('data.reference');
        $this->postJson('/api/v1/booking-requests', $body + ['idempotency_key' => 'abc-123'])->assertOk()->assertJsonPath('data.reference', $first)->assertJsonPath('data.duplicate', true);
        // Same phone in another format, same trip, minutes later: the same lead.
        $this->postJson('/api/v1/booking-requests', ['phone' => '+971 50 111 2233'] + $body)->assertOk()->assertJsonPath('data.reference', $first);
        $this->assertSame(1, Lead::count());
    }

    public function test_invalid_unpublished_and_full_requests_are_refused(): void
    {
        $p = $this->package(['default_capacity' => 2]);
        $d = $p->departures()->create(['start_date' => today()->addDays(10)]);
        $hidden = $this->package(['is_published' => false]);

        $this->postJson('/api/v1/booking-requests', [])->assertUnprocessable()->assertJsonValidationErrors(['product_type', 'product_slug', 'name', 'phone']);
        $this->postJson('/api/v1/booking-requests', ['product_type' => 'package', 'product_slug' => $hidden->slug, 'name' => 'X', 'phone' => '0501234567'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_slug');
        $this->postJson('/api/v1/booking-requests', ['product_type' => 'package', 'product_slug' => $p->slug, 'name' => 'X', 'phone' => '0501234567', 'guests' => 3, 'departure_id' => $d->id])
            ->assertUnprocessable()->assertJsonValidationErrors('guests');
        $this->postJson('/api/v1/booking-requests', ['product_type' => 'package', 'product_slug' => $p->slug, 'name' => 'X', 'phone' => '0501234567', 'guests' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('departure_id');
        $this->assertSame(0, Lead::count());
    }

    public function test_bot_submissions_are_ignored_and_requests_are_rate_limited(): void
    {
        $trip = $this->trip(null, ['is_published' => true]);
        $date = $this->tripDate($trip, today()->addDays(4));
        $this->postJson('/api/v1/booking-requests', ['website' => 'http://spam.example', 'name' => 'Bot'])->assertStatus(202);
        $this->assertSame(0, Lead::count());

        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->postJson('/api/v1/booking-requests', [
                'product_type' => 'yacht_trip', 'product_slug' => $trip->slug, 'departure_id' => $date->id,
                'name' => 'N'.$i, 'phone' => '05000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ])->status();
        }
        $this->assertContains(429, $statuses);
    }

    public function test_api_access_from_browsers_is_limited_to_the_website_addresses_in_settings(): void
    {
        $this->settings(['website_origins' => ['https://www.redprimerentals.com/']]);
        $this->assertContains('https://www.redprimerentals.com', Website::allowedOrigins());

        config(['cors.allowed_origins' => Website::allowedOrigins()]);
        $this->call('OPTIONS', '/api/v1/packages', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.redprimerentals.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://www.redprimerentals.com');

        $other = $this->call('OPTIONS', '/api/v1/packages', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://evil.example', $other->headers->get('Access-Control-Allow-Origin'));
    }
}
