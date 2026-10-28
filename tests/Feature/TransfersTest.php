<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Pages\Integrations;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\TransferFixedRoute;
use App\Models\TransferRate;
use App\Models\TransferService;
use App\Models\VehicleCategory;
use App\Support\Drivers\DriverWork;
use App\Support\Maps\GoogleMaps;
use App\Support\Pricing\TransferPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 28 Oct-2, Phase 4: limousine & transfers, Google Maps, the integration registry, optional price approval. */
class TransfersTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected TransferService $airport;

    protected TransferService $hourly;

    protected VehicleCategory $sedan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        RateLimiter::clear('api-booking-requests');
        $this->airport = TransferService::create(['name' => 'Airport pickup', 'journey_type' => 'one_way', 'is_airport' => true, 'is_published' => true]);
        $this->hourly = TransferService::create(['name' => 'Hourly chauffeur', 'journey_type' => 'hourly', 'is_published' => true]);
        $this->sedan = VehicleCategory::create(['name' => 'Business sedan', 'passengers' => 3, 'luggage' => 3, 'is_published' => true]);
        TransferRate::create(['vehicle_category_id' => $this->sedan->id, 'base_fee' => 50, 'per_km' => 4, 'min_fare' => 150,
            'per_hour' => 120, 'min_hours' => 3, 'half_day_price' => 400, 'half_day_hours' => 5, 'full_day_price' => 700, 'full_day_hours' => 10,
            'airport_fee' => 25, 'per_stop' => 30, 'free_waiting_minutes' => 15, 'waiting_per_hour' => 60, 'toll_fee' => 4,
            'night_percent' => 20, 'night_from' => '22:00', 'night_to' => '06:00', 'return_discount_percent' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function maps(bool $browserKey = true): Integration
    {
        $i = Integration::for('google_maps');
        $i->putSecrets(array_filter(['server_key' => 'AIza-server-test', 'browser_key' => $browserKey ? 'AIza-browser-test' : null]), 'test');
        $i->fill(['enabled' => true, 'mode' => 'test', 'status' => 'connected', 'config' => ['region' => 'ae']])->save();

        return $i;
    }

    protected function fakeRoute(int $meters = 32000, string $duration = '1980s'): void
    {
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => $meters, 'duration' => $duration, 'legs' => [['distanceMeters' => $meters, 'duration' => $duration]]]]]),
            'places.googleapis.com/*' => Http::response(['suggestions' => [['placePrediction' => ['text' => ['text' => 'Dubai Marina, Dubai, UAE']]]]]),
        ]);
    }

    protected function transfer(array $attrs = []): Booking
    {
        return Booking::create($attrs + [
            'status' => BookingStatus::Confirmed, 'customer_id' => $this->customer()->id, 'service_type' => ServiceType::Transfer,
            'transfer_service_id' => $this->airport->id, 'vehicle_category_id' => $this->sedan->id,
            'start_at' => '2026-11-10 14:00', 'pickup_location' => 'DXB Terminal 3', 'dropoff_location' => 'Atlantis The Palm',
            'flight_number' => 'EK 202', 'guests' => 2, 'route_km' => 32, 'route_minutes' => 33, 'route_source' => 'google',
        ]);
    }

    /* ---------- prices ---------- */

    public function test_distance_price_with_airport_fee_stops_waiting_tolls(): void
    {
        $q = TransferPricing::calculate(['transfer_service' => $this->airport, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 14:00',
            'route_km' => 32, 'route_minutes' => 33, 'stops' => [['address' => 'Mall of the Emirates']], 'waiting_minutes' => 40,
            'pickup_location' => 'DXB T3', 'dropoff_location' => 'Atlantis']);
        $this->assertSame(178.0, $q['base_amount'], '50 + 32 km × 4');
        $lines = collect($q['lines'])->keyBy('key');
        $this->assertSame(25.0, $lines['transfer:airport']['amount']);
        $this->assertSame(30.0, $lines['transfer:stops']['amount']);
        $this->assertSame(30.0, $lines['transfer:waiting']['amount'], '40 − 15 free = 25 → 30 min at 60/h');
        $this->assertSame(4.0, $lines['transfer:tolls']['amount']);
        $this->assertArrayNotHasKey('transfer:night', $lines->all());
        $this->assertSame(267.0, $q['standard_amount']);

        // Short trip at night: minimum fare, then 20 % night surcharge on it.
        $n = TransferPricing::calculate(['transfer_service' => $this->airport, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 23:30', 'route_km' => 5]);
        $this->assertSame(150.0, $n['base_amount']);
        $this->assertSame(30.0, collect($n['lines'])->firstWhere('key', 'transfer:night')['amount']);

        // Return journey: same again less 10 %; tolls twice.
        $r = TransferPricing::calculate(['transfer_service' => $this->airport, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 14:00',
            'route_km' => 32, 'journey_type' => 'return', 'return_at' => '2026-11-12 10:00']);
        $lines = collect($r['lines'])->keyBy('key');
        $this->assertSame(160.2, $lines['transfer:return']['amount']);
        $this->assertSame(8.0, $lines['transfer:tolls']['amount']);
    }

    public function test_fixed_route_and_hourly_pick_the_right_price(): void
    {
        TransferFixedRoute::create(['vehicle_category_id' => $this->sedan->id, 'name' => 'DXB → Marina', 'from_match' => 'DXB, Dubai International', 'to_match' => 'Marina, JBR', 'price' => 199]);
        $f = TransferPricing::calculate(['transfer_service' => $this->airport, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 14:00',
            'pickup_location' => 'JBR Walk', 'dropoff_location' => 'DXB Terminal 1']);
        $this->assertSame(199.0, $f['base_amount'], 'both ways, no distance needed');
        $this->assertSame('Fixed route: DXB → Marina', $f['base_label']);

        $two = TransferPricing::calculate(['transfer_service' => $this->hourly, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 12:00']);
        $this->assertSame(360.0, $two['base_amount'], 'minimum 3 hours × 120');
        $four = TransferPricing::calculate(['transfer_service' => $this->hourly, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 14:00']);
        $this->assertSame(400.0, $four['base_amount'], 'half day is cheaper than 4 × 120');
        $nine = TransferPricing::calculate(['transfer_service' => $this->hourly, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 08:00', 'end_at' => '2026-11-10 17:00']);
        $this->assertSame(700.0, $nine['base_amount']);

        $this->assertNull(TransferPricing::calculate(['transfer_service' => $this->airport, 'vehicle_category' => $this->sedan, 'start_at' => '2026-11-10 14:00']));
        $this->assertSame('Calculate the route (or type the distance) to price this transfer.', TransferPricing::problem(['transfer_service_id' => $this->airport->id, 'vehicle_category_id' => $this->sedan->id, 'start_at' => '2026-11-10 14:00']));
    }

    /* ---------- bookings ---------- */

    public function test_transfer_booking_rules_end_time_driver_job_and_holds_the_car(): void
    {
        $this->actingAs($this->user('Manager'));
        $car = $this->car(['name' => 'S-Class', 'vehicle_category_id' => $this->sedan->id]);
        $driver = $this->user('Driver', ['name' => 'Imran']);
        $b = $this->transfer(['bookable_id' => $car->id, 'driver_id' => $driver->id, 'waiting_minutes' => 30]);

        $this->assertSame('car', $b->bookable_type);
        $this->assertSame('2026-11-10 15:03:00', $b->end_at->toDateTimeString(), '33 min drive + 30 min waiting');
        $this->assertEqualsWithDelta(178 + 25 + 15 + 4, (float) $b->total, 0.01);
        $this->assertSame('transfer', $b->revenueCategory());
        $job = $b->jobs()->where('type', 'transfer')->firstOrFail();
        $this->assertSame($driver->id, $job->driver_id);

        // The car is held: a rental at the same time is refused.
        try {
            $this->booking($car, '2026-11-10 13:00', '2026-11-11 10:00');
            $this->fail('car double booked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.end_at', $e->errors());
        }
        // The chauffeur is busy: another job with him clashes.
        try {
            $this->booking($this->car(), '2026-11-10 14:30', '2026-11-10 18:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $driver->id]);
            $this->fail('driver double booked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Imran is already busy on '.$b->reference, $e->errors()['data.driver_id'][0]);
        }

        // The chauffeur starts and finishes it on the phone; no money is recorded.
        $this->actingAs($driver);
        DriverWork::startCharter($job, $driver, ['guests' => 2]);
        $this->assertSame(BookingStatus::InProgress, $b->fresh()->status);
        DriverWork::finishCharter($job->fresh(), $driver, ['notes' => 'Waited 10 more minutes']);
        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame(1, $b->transactions()->count(), 'only the balance row');
    }

    public function test_transfer_validation(): void
    {
        $this->actingAs($this->user('Manager'));
        $cases = [
            'data.guests' => ['guests' => 5],
            'data.flight_number' => ['flight_number' => null],
            'data.dropoff_location' => ['dropoff_location' => null],
            'data.vehicle_category_id' => ['vehicle_category_id' => null],
            'data.return_at' => ['journey_type' => 'return'],
        ];
        foreach ($cases as $field => $attrs) {
            try {
                $this->transfer($attrs);
                $this->fail('expected '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $field);
            }
        }
        // As an inquiry the flight can come later.
        $this->assertNotNull($this->transfer(['flight_number' => null, 'status' => BookingStatus::Inquiry])->id);
        // A typed distance is marked manual.
        $b = $this->transfer(['route_source' => null]);
        $b->update(['route_km' => 40]);
        $this->assertSame('manual', $b->fresh()->route_source);
    }

    public function test_quotation_to_booking_keeps_the_journey_and_price(): void
    {
        $this->actingAs($this->user('Manager'));
        $q = Quotation::create(['customer_id' => $this->customer()->id, 'service_type' => ServiceType::Transfer, 'transfer_service_id' => $this->airport->id,
            'vehicle_category_id' => $this->sedan->id, 'start_at' => '2026-11-10 14:00', 'pickup_location' => 'DXB T3', 'dropoff_location' => 'Atlantis',
            'flight_number' => 'EK 202', 'guests' => 2, 'route_km' => 32, 'route_minutes' => 33, 'route_source' => 'google', 'valid_until' => '2026-11-08',
            'stops' => [['address' => 'Mall of the Emirates']]]);
        $this->assertEqualsWithDelta(178 + 25 + 30 + 4, (float) $q->total, 0.01);
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        \App\Support\Sales\QuotationDecision::accept($q->fresh(), 'staff', 'Omar');

        // The rate changes afterwards: the accepted price stays.
        TransferRate::first()->update(['per_km' => 9]);
        Livewire::test(\App\Filament\Resources\QuotationResource\Pages\EditQuotation::class, ['record' => $q->id])
            ->callAction('convert', ['status' => 'confirmed', 'advance' => 0])->assertHasNoActionErrors();
        $b = Booking::firstOrFail();
        $this->assertSame(ServiceType::Transfer, $b->service_type);
        $this->assertSame('Atlantis', $b->dropoff_location);
        $this->assertSame([['address' => 'Mall of the Emirates']], $b->stops);
        $this->assertSame((string) $q->fresh()->total, (string) $b->total);
    }

    /* ---------- Google Maps & integrations ---------- */

    public function test_google_route_errors_and_secrets(): void
    {
        try {
            GoogleMaps::route('A', 'B');
            $this->fail('not set up');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not set up', $e->getMessage());
        }

        $i = $this->maps();
        $this->fakeRoute(32400, '1990s');
        $r = GoogleMaps::route('DXB T3', 'Atlantis', ['Mall of the Emirates'], Carbon::parse('2026-11-10 14:00'));
        $this->assertSame(32.4, $r['km']);
        $this->assertSame(34, $r['minutes']);
        Http::assertSent(fn ($req) => $req->hasHeader('X-Goog-Api-Key', 'AIza-server-test')
            && $req['origin']['address'] === 'DXB T3' && $req['intermediates'][0]['address'] === 'Mall of the Emirates'
            && $req['departureTime'] === '2026-11-10T10:00:00Z' && $req['travelMode'] === 'DRIVE');
        $this->assertSame(['Dubai Marina, Dubai, UAE' => 'Dubai Marina, Dubai, UAE'], GoogleMaps::search('Dubai Mar'));

        // Secrets are encrypted in the database and never in arrays / JSON.
        $raw = DB::table('integrations')->where('provider', 'google_maps')->value('secrets');
        $this->assertStringNotContainsString('AIza-server-test', $raw);
        $this->assertArrayNotHasKey('secrets', $i->fresh()->toArray());
        $this->assertStringContainsString('key=AIza-browser-test', GoogleMaps::embedUrl('A', 'B'), 'only the browser key goes to the page');
        $this->assertStringNotContainsString('AIza-server-test', (string) GoogleMaps::embedUrl('A', 'B'));

        Http::swap(new \Illuminate\Http\Client\Factory); // forget the earlier fake answers
        Http::fake(['routes.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 403)]);
        try {
            GoogleMaps::route('DXB', 'Atlantis');
            $this->fail('403');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('API key not valid', $e->getMessage());
            $this->assertSame('error', $i->fresh()->status);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['routes.googleapis.com/*' => Http::response([])]);
        $this->expectExceptionMessage('No road route found');
        GoogleMaps::route('Nowhere 1', 'Nowhere 2');
    }

    public function test_integrations_screen_saves_tests_and_disconnects(): void
    {
        $this->actingAs($this->user('Admin'));
        Livewire::test(Integrations::class)
            ->callAction('setUp', ['enabled' => true, 'config_region' => 'ae', 'secret_server_key' => 'AIza-new-key'], ['provider' => 'google_maps'])
            ->assertHasNoActionErrors();
        $i = Integration::for('google_maps');
        $this->assertSame('configured', $i->status, 'saved keys are not "connected"');
        $this->assertSame('AIza-new-key', $i->secret('server_key'));
        $line = AuditLog::where('event', 'integration')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('AIza-new-key', json_encode($line->toArray()));
        $this->assertSame(['test.server_key'], $line->new_values['keys_changed']);

        // Empty key field keeps the saved key.
        Livewire::test(Integrations::class)->callAction('setUp', ['enabled' => true, 'config_region' => 'ae', 'secret_server_key' => ''], ['provider' => 'google_maps']);
        $this->assertSame('AIza-new-key', Integration::for('google_maps')->secret('server_key'));

        $this->fakeRoute();
        Livewire::test(Integrations::class)->callAction('test', [], ['provider' => 'google_maps']);
        $this->assertSame('connected', Integration::for('google_maps')->status);

        Livewire::test(Integrations::class)->callAction('disconnect', [], ['provider' => 'google_maps']);
        $i = Integration::for('google_maps');
        $this->assertFalse($i->enabled);
        $this->assertNull($i->secret('server_key'));
        $this->assertSame('not_set_up', $i->status);
    }

    public function test_only_admins_open_integrations(): void
    {
        $this->actingAs($this->user('Manager'));
        $this->get(Integrations::getUrl())->assertForbidden();
    }

    /* ---------- website ---------- */

    public function test_website_transfer_quote_and_request(): void
    {
        $this->getJson('/api/v1/transfer-services')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.needs', ['pickup', 'dropoff', 'flight_number']);
        VehicleCategory::create(['name' => 'Hidden van', 'is_published' => false]);
        $this->getJson('/api/v1/vehicle-categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_price.amount', 150);

        $ask = ['product_type' => 'transfer', 'product_slug' => $this->sedan->slug, 'service' => $this->airport->slug,
            'pickup' => 'DXB Terminal 3', 'dropoff' => 'Atlantis The Palm', 'start_at' => '2026-11-10 14:00', 'passengers' => 2, 'route_km' => 1]; // route_km ignored
        $this->postJson('/api/v1/price-estimate', $ask)->assertOk()->assertJsonPath('data.price', null)->assertJsonPath('data.note', 'Price on request. Our team will send you a quotation.');

        $this->maps();
        $this->fakeRoute(32000, '1980s');
        $this->car(['vehicle_category_id' => $this->sedan->id]);
        $res = $this->postJson('/api/v1/price-estimate', $ask)->assertOk();
        $res->assertJsonPath('data.route.km', 32)->assertJsonPath('data.price.total', 207)->assertJsonPath('data.available', true);

        $this->postJson('/api/v1/price-estimate', ['passengers' => 9] + $ask)->assertUnprocessable()->assertJsonValidationErrors('passengers');

        $this->postJson('/api/v1/booking-requests', $ask + ['request_type' => 'booking_request', 'flight_number' => 'EK 202', 'name' => 'Web Guest', 'phone' => '+971 55 123 9999'])
            ->assertCreated()->assertJsonPath('data.price_estimate.amount', 207)->assertJsonPath('data.confirmed', false);
        $lead = Lead::firstOrFail();
        $this->assertSame(ServiceType::Transfer, $lead->service_type);
        $this->assertStringContainsString('Pickup: DXB Terminal 3', $lead->message);
        $this->assertStringContainsString('Flight: EK 202', $lead->message);
        $this->assertSame(0, Booking::count());
    }

    /* ---------- optional price approval ---------- */

    public function test_price_approval_when_switched_on(): void
    {
        $this->settings(['price_max_discount_percent' => 10, 'price_approval_required' => true]);
        $agent = $this->user('Agent');
        $this->actingAs($agent);
        $b = $this->transfer(['status' => BookingStatus::Inquiry, 'discount' => 60, 'adjustment_reason' => 'VIP']);
        $this->assertSame('pending', $b->price_approval);
        try {
            $b->update(['status' => BookingStatus::Confirmed]);
            $this->fail('confirmed while waiting');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.status', $e->errors());
        }
        try {
            $b->fresh()->approvePrice($agent);
            $this->fail('agents cannot approve');
        } catch (ValidationException) {
            $this->assertSame('pending', $b->fresh()->price_approval);
        }

        $this->actingAs($manager = $this->user('Manager'));
        $b->fresh()->approvePrice($manager);
        $this->assertSame('approved', $b->fresh()->price_approval);
        $b->fresh()->update(['status' => BookingStatus::Confirmed]);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);

        // Switched off (default): too big a discount is refused as before.
        $this->settings(['price_approval_required' => false]);
        $this->actingAs($agent);
        $this->expectException(ValidationException::class);
        $this->transfer(['status' => BookingStatus::Inquiry, 'discount' => 60, 'adjustment_reason' => 'VIP']);
    }

    public function test_booking_form_opens_for_a_transfer(): void
    {
        $this->actingAs($this->user('Manager'));
        $b = $this->transfer();
        Livewire::test(\App\Filament\Resources\BookingResource\Pages\EditBooking::class, ['record' => $b->id])
            ->assertOk()->assertSee('Journey')
            ->assertFormSet(['dropoff_location' => 'Atlantis The Palm', 'flight_number' => 'EK 202', 'transfer_service_id' => $this->airport->id])
            ->assertFormFieldIsVisible('dropoff_location')->assertFormFieldIsVisible('flight_number')->assertFormFieldIsHidden('drive_mode');
        $this->get(\App\Filament\Resources\BookingResource::getUrl('create'))->assertOk();
        foreach (['/crm/transfer-services', '/crm/vehicle-categories', '/crm/transfer-rates', '/crm/transfer-fixed-routes'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
