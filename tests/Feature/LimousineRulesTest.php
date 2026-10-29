<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\Quotation;
use App\Models\TransferRate;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use App\Support\Maps\GoogleMaps;
use App\Support\Pricing\TransferPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 29 Oct, Module 1: Google road distance × rate per km, overrides, confirmation rules, stable prices. Google mocked. */
class LimousineRulesTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected TransferService $p2p;

    protected VehicleCategory $suv;

    protected TransferRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        Http::preventStrayRequests();
        $this->p2p = TransferService::create(['name' => 'Point to point', 'journey_type' => 'one_way', 'is_published' => true]);
        $this->suv = VehicleCategory::create(['name' => 'Luxury SUV', 'passengers' => 6, 'is_published' => true]);
        $this->rate = TransferRate::create(['vehicle_category_id' => $this->suv->id, 'per_km' => 5]);
        $i = Integration::for('google_maps');
        $i->putSecrets(['server_key' => 'AIza-test'], 'test');
        $i->fill(['enabled' => true, 'mode' => 'test', 'status' => 'connected'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function google(int $meters): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => $meters, 'duration' => '1800s', 'legs' => [['distanceMeters' => $meters, 'duration' => '1800s']]]]])]);
    }

    protected function base(array $a = []): array
    {
        return $a + ['status' => BookingStatus::Confirmed, 'customer_id' => $this->customer()->id, 'service_type' => ServiceType::Transfer,
            'transfer_service_id' => $this->p2p->id, 'vehicle_category_id' => $this->suv->id, 'start_at' => '2026-11-10 14:00',
            'pickup_location' => 'Dubai Marina', 'dropoff_location' => 'Downtown Dubai', 'guests' => 2];
    }

    /** What the "Calculate route" button does: Google distance, signed by the server. */
    protected function routed(array $a): array
    {
        $r = GoogleMaps::route($a['pickup_location'], $a['dropoff_location']);

        return $a + ['route_km' => $r['km'], 'route_minutes' => $r['minutes'], 'route_source' => 'google',
            'route' => GoogleMaps::stamp($a['pickup_location'], $a['dropoff_location'], [], $r['km'], null, ['meters' => $r['meters']])];
    }

    public function test_road_distance_from_google_times_rate_with_rounding(): void
    {
        $this->actingAs($this->user('Manager'));
        $this->google(28640);
        $b = Booking::create($this->routed($this->base()));
        $this->assertSame(28.6, (float) $b->route_km);                 // 28,640 m → 28.6 km (Bilal: to 0.1 km)
        $this->assertSame(143.0, (float) $b->base_amount);              // 28.6 × AED 5
        $this->assertSame('28.6 km × AED 5.00', $b->price_breakdown['base_label']);
        $this->assertSame(28640, $b->route['meters']);
        Http::assertSent(fn ($r) => $r['travelMode'] === 'DRIVE' && $r->hasHeader('X-Goog-Api-Key'));

        $this->settings(['transfer_km_rounding' => 'up_1']);
        $this->assertSame(29.0, GoogleMaps::km(28100));
        $this->settings(['transfer_km_rounding' => '0.01']);
        $this->assertSame(28.64, GoogleMaps::km(28640));
    }

    public function test_route_failures_never_guess_a_distance(): void
    {
        Http::fake(['routes.googleapis.com/*' => Http::sequence()->push(['error' => ['message' => 'quota']], 429)->push(['routes' => []])->push(['routes' => [['distanceMeters' => 0, 'duration' => '0s']]])]);
        foreach (['Google Maps error', 'No road route', 'without a distance'] as $expected) {
            try {
                GoogleMaps::route('Dubai Marina', 'Downtown Dubai');
                $this->fail('expected a failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        // No route and no reason: it can't be confirmed.
        $this->actingAs($this->user('Manager'));
        try {
            Booking::create($this->base());
            $this->fail('confirmed without a route');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.route_km', $e->errors());
        }
        $this->assertNotNull(Booking::create($this->base(['status' => BookingStatus::Inquiry]))->id); // an enquiry can wait
    }

    public function test_changed_addresses_or_typed_km_need_permission_and_reason(): void
    {
        $this->actingAs($this->user('Manager'));
        $this->google(28640);
        $b = Booking::create($this->routed($this->base()));
        $this->assertSame('google', $b->route_source);

        // The address changed after the route: no longer the Google distance.
        try {
            $b->update(['dropoff_location' => 'Dubai Airport T3']);
            $this->fail('changed address kept as Google');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.route_km', $e->errors());
        }
        $b->refresh()->update(['route_km' => 35, 'route_override_reason' => 'Customer asked to go via Al Khail road']);
        $b->refresh();
        $this->assertSame('manual', $b->route_source);
        $this->assertSame(28.6, (float) $b->route['google_km']);       // the original Google distance is kept
        $this->assertSame(auth()->id(), $b->route_override_by);
        $this->assertSame(175.0, (float) $b->base_amount);

        // Someone without "Change prices" can't type a distance.
        $this->actingAs($this->user('Operations'));
        try {
            $b->update(['route_km' => 20]);
            $this->fail('Operations typed a distance');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only people allowed to change prices', collect($e->errors())->flatten()->first());
        }
    }

    public function test_no_rate_means_no_automatic_price(): void
    {
        $van = VehicleCategory::create(['name' => 'Van', 'passengers' => 12]);
        $this->actingAs($this->user('Manager'));
        $in = $this->base(['vehicle_category_id' => $van->id, 'route_km' => 30, 'route_source' => 'manual', 'route_override_reason' => 'Maps not set up']);
        $this->assertNull(TransferPricing::calculate($in + ['transfer_service' => $this->p2p, 'vehicle_category' => $van]));
        $this->assertStringContainsString('No transfer rate', TransferPricing::problem($in));
        try {
            Booking::create($in);
            $this->fail('confirmed without a rate');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.base_amount', $e->errors());
        }
        $b = Booking::create($in + ['base_amount' => 600, 'adjustment_reason' => 'Van hired in, price agreed with the client']);
        $this->assertSame(600.0, (float) $b->base_amount);
        $this->assertNull($b->standard_amount);
    }

    public function test_confirmed_price_stays_after_a_rate_change_and_quotes_need_a_route(): void
    {
        $this->actingAs($this->user('Manager'));
        $this->google(28640);
        $b = Booking::create($this->routed($this->base()));
        try {
            $this->rate->update(['per_km' => 9]);
            $this->fail('a used rate was edited');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.per_km', $e->errors());
        }
        // New price from tomorrow: end the old rate, add a new one.
        $this->rate->refresh()->update(['ends_on' => '2026-11-02']);
        TransferRate::create(['vehicle_category_id' => $this->suv->id, 'per_km' => 9, 'starts_on' => '2026-11-03']);
        $b->update(['special_requests' => 'Water bottles', 'guests' => 3]);
        $this->assertSame(143.0, (float) $b->fresh()->base_amount); // the confirmed booking keeps its rate
        $this->assertSame(5.0, (float) $b->fresh()->rate);
        $this->assertSame(257.4, (float) Booking::create($this->routed($this->base()))->base_amount); // a new booking uses the new rate

        // Only on purpose, by someone allowed to change prices, and logged.
        $b->fresh()->repriceWithCurrentRate('Customer accepted the new tariff');
        $this->assertSame(257.4, (float) $b->fresh()->base_amount);
        $this->assertTrue(\App\Models\AuditLog::where('auditable_id', $b->id)->where('note', 'like', 'Priced again with the current transfer rate%')->exists());

        $q = Quotation::create($this->base(['status' => QuotationStatus::Draft, 'valid_until' => '2026-11-08', 'route_km' => 30, 'route_source' => 'manual']));
        try {
            $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
            $this->fail('quotation sent with a typed distance and no reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.route_km', $e->errors());
        }
    }
}
