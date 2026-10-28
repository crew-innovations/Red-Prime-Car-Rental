<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DocumentStatus;
use App\Enums\DriveMode;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Models\Booking;
use App\Models\CustomerDocument;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Transaction;
use App\Support\Accounts\ProfitReport;
use App\Support\BookingFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/**
 * 28 Oct brief, Priority 10: whole journeys, from enquiry to money, run through
 * the same code staff, customers and the website use.
 */
class EndToEndTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_car_rental_from_enquiry_to_profit(): void
    {
        $manager = $this->user('Manager');
        $agent = $this->user('Agent');
        $car = $this->car(['name' => 'Range Rover Sport', 'is_published' => true]);

        // 1. Website enquiry becomes a lead; the manager gives it to the agent.
        $this->postJson('/api/v1/booking-requests', ['product_type' => 'car', 'product_slug' => $car->slug, 'request_type' => 'booking_request',
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'name' => 'Omar Faruk', 'phone' => '+971 50 777 8899', 'email' => 'omar@example.com'])
            ->assertCreated()->assertJsonPath('data.price_estimate.amount', 2000);
        $lead = Lead::firstOrFail();
        $this->assertSame(Pipeline::Car, $lead->pipeline);
        $this->actingAs($manager);
        $lead->update(['assigned_to' => $agent->id]);
        $this->assertSame('pending', $lead->fresh()->assignment_status);

        // 2. The agent accepts it and sends a quotation with a discount (reason kept).
        $this->actingAs($agent);
        $lead->fresh()->acceptAssignment();
        $q = Quotation::create(['lead_id' => $lead->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'valid_until' => '2026-11-08', 'discount' => 200, 'adjustment_reason' => 'Returning customer', 'terms' => 'Fuel same level']);
        $this->assertSame('2000.00', $q->standard_amount);
        $this->assertSame('1800.00', $q->total);
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);

        // 3. The customer accepts on the secure link.
        auth()->logout();
        $this->post('/quote/'.$q->public_token.'/accept', ['name' => 'Omar Faruk', 'agree' => '1'])->assertRedirect();
        $q->refresh();
        $this->assertSame(QuotationStatus::Accepted, $q->status);
        $this->assertNotNull($q->customer_id, 'the customer profile was created');
        $this->assertSame('Omar Faruk', $q->accepted_name);

        // 4. Documents arrive and are approved; the agent creates the booking at the agreed price.
        foreach (['emirates_id', 'driving_licence'] as $type) {
            CustomerDocument::create(['customer_id' => $q->customer_id, 'type' => $type, 'status' => DocumentStatus::Approved, 'expires_on' => '2027-12-31', 'path' => 'customer-documents/'.$type.'.jpg']);
        }
        $this->actingAs($agent);
        Livewire::test(EditQuotation::class, ['record' => $q->id])->callAction('convert', ['status' => 'confirmed', 'advance' => 800, 'method' => 'card'])->assertHasNoActionErrors();
        $b = Booking::firstOrFail();
        $this->assertSame(BookingStatus::Confirmed, $b->status);
        $this->assertSame('1800.00', $b->total);
        $this->assertSame('1000.00', $b->balance);
        $this->assertSame(CarStatus::Reserved, $car->fresh()->status);

        // 5. Handover with a deposit; return a day later with a fuel charge; everything settled.
        $this->actingAs($manager);
        Carbon::setTestNow('2026-11-10 10:00:00');
        BookingFlow::deliver($b->fresh(), ['delivered_at' => now(), 'delivery_km' => 10100, 'delivery_fuel' => 'full', 'deposit_received' => 2000, 'deposit_method' => 'card']);
        $this->assertSame(CarStatus::Rented, $car->fresh()->status);
        Carbon::setTestNow('2026-11-12 09:30:00');
        BookingFlow::receive($b->fresh(), ['returned_at' => now(), 'return_km' => 10500, 'return_fuel' => 'half', 'return_condition' => 'good',
            'charges' => ['fuel' => 150], 'payment_amount' => 1150, 'payment_method' => 'card', 'refund_deposit' => true, 'refund_method' => 'card', 'after_return' => 'ready']);
        $b->refresh();
        $this->assertSame(BookingStatus::Completed, $b->status);
        $this->assertSame('0.00', $b->balance);
        $this->assertSame(0.0, $b->depositHeld());
        $this->assertSame(CarStatus::Available, $car->fresh()->status);
        $this->assertSame('completed', $lead->fresh()->stage);

        // 6. Invoice and profit: deposit in and out never touches revenue or costs.
        $invoice = Invoice::issueFor($b);
        $this->assertEqualsWithDelta(1950, (float) $invoice->total, 0.01);
        $this->expense(['booking_id' => $b->id, 'category' => 'cleaning', 'amount' => 100]);
        $s = ProfitReport::forRange(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59:59'))->summary();
        $this->assertEqualsWithDelta(1950, $s['earned'], 0.01);
        $this->assertEqualsWithDelta(1950, $s['received'], 0.01);
        $this->assertEqualsWithDelta(1850, $s['contribution'], 0.01);
        $this->assertEqualsWithDelta(0, $s['deposits_held'], 0.01);
    }

    public function test_hourly_yacht_charter_with_captain(): void
    {
        $this->actingAs($this->user('Manager'));
        $yacht = $this->yacht(['minimum_hours' => 2]);
        $captain = $this->user('Driver');
        $captain->driverProfile()->create(['work_types' => ['captain']]);
        $b = $this->booking($yacht, '2026-11-10 16:00', '2026-11-10 19:00', ['guests' => 8, 'crew_mode' => 'with_crew', 'captain_id' => $captain->id]);

        $this->assertSame('2700.00', $b->total, '3 hours × 900');
        $job = $b->jobs()->where('type', 'charter')->first();
        $this->assertNotNull($job, 'the captain gets the charter job');
        $this->assertSame($captain->id, $job->driver_id);

        // Same captain cannot be on a clashing charter.
        $other = $this->yacht();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->booking($other, '2026-11-10 18:00', '2026-11-10 20:00', ['crew_mode' => 'with_crew', 'captain_id' => $captain->id]);
    }

    public function test_daily_yacht_and_package_prices_come_from_the_engine(): void
    {
        $this->actingAs($this->user('Manager'));
        $yacht = $this->yacht();
        $day = $this->booking($yacht, '2026-11-15 09:00', '2026-11-15 17:00');
        $this->assertGreaterThan(0, (float) $day->total);
        $this->assertSame((float) $day->total, (float) \App\Support\Pricing\PriceEngine::calculate(['service_type' => 'yacht', 'item' => $yacht, 'start_at' => '2026-11-15 09:00', 'end_at' => '2026-11-15 17:00'])['standard_amount']);

        $trip = $this->trip($yacht, ['price' => 350, 'price_unit' => 'per_person', 'guest_capacity' => 10]);
        $date = $this->tripDate($trip, '2026-11-20', ['available_slots' => 10]);
        $seat = $this->booking($trip, '2026-11-20 16:00', '2026-11-20 18:00', ['guests' => 4, 'trip_date_id' => $date->id]);
        $this->assertSame('1400.00', $seat->total);
        $this->assertSame(6, \App\Support\Availability::seatsLeft($date->fresh()));
    }

    public function test_security_regressions_hold(): void
    {
        $agent = $this->user('Agent');
        $other = $this->user('Agent');
        $this->actingAs($other);
        $theirs = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['assigned_to' => $other->id]);

        $this->actingAs($agent);
        $this->get('/crm/bookings/'.$theirs->id.'/edit')->assertNotFound();
        $this->get('/crm/profit-reports')->assertForbidden();
        $this->get('/crm/business-settings')->assertForbidden();
        $this->get(route('pdf.confirmation', $theirs))->assertNotFound();

        $this->assertSame(0, Transaction::query()->where('type', 'expense')->count());
    }

    public function test_guests_are_sent_to_the_login_pages(): void
    {
        $this->get('/crm')->assertRedirect('/crm/login');
        $this->get('/crm/bookings')->assertRedirect('/crm/login');
        $this->get('/account')->assertRedirect('/account/login');
    }

    public function test_drivers_cannot_open_money_or_bookings(): void
    {
        $this->actingAs($this->user('Driver')); // one user per test: the session guard catches a switch
        $this->get('/crm/transactions')->assertForbidden();
        $this->get('/crm/bookings')->assertForbidden();
        $this->get('/crm/profit-reports')->assertForbidden();
    }

    protected function expense(array $attrs): Transaction
    {
        return Transaction::create($attrs + ['type' => 'expense', 'date' => today(), 'status' => 'paid', 'description' => 'Cost']);
    }
}
