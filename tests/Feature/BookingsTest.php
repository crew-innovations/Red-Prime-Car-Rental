<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\DriveMode;
use App\Enums\ServiceType;
use App\Enums\YachtStatus;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Lead;
use App\Support\Availability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Checklist 3 (car bookings), 4 (drivers), 6 (yachts), 7 (trips), 11 (changes), 12 (availability). */
class BookingsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Sales'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------- prices ---------- */

    public function test_car_prices_use_hourly_daily_weekly_monthly_and_driver_rates(): void
    {
        $car = $this->car();
        $this->assertSame(['hour', 3, 150.0, 450.0], Booking::quote($car, '2026-11-03 10:00', '2026-11-03 13:00'));
        $this->assertSame(['day', 3, 1000.0, 3000.0], Booking::quote($car, '2026-11-03 10:00', '2026-11-06 10:00'));
        $this->assertSame('week', Booking::quote($car, '2026-11-03 10:00', '2026-11-10 10:00')[0]);
        $this->assertSame('month', Booking::quote($car, '2026-11-01 10:00', '2026-12-01 10:00')[0]);
        $this->assertSame(['day', 2, 1800.0, 3600.0], Booking::quote($car, '2026-11-03 10:00', '2026-11-05 10:00', DriveMode::WithDriver->value));
        $this->assertSame(['hour', 4, 250.0, 1000.0], Booking::quote($car, '2026-11-03 10:00', '2026-11-03 14:00', DriveMode::WithDriver->value));
    }

    public function test_price_thresholds_follow_business_settings(): void
    {
        $car = $this->car();
        $this->assertSame('day', Booking::quote($car, '2026-11-03 10:00', '2026-11-08 10:00')[0]);
        $this->settings(['weekly_from_days' => 5, 'hourly_max_hours' => 12]);
        $this->assertSame('week', Booking::quote($car, '2026-11-03 10:00', '2026-11-08 10:00')[0]);
        $this->assertSame('hour', Booking::quote($car, '2026-11-03 08:00', '2026-11-03 18:00')[0]);
    }

    public function test_total_includes_extras_minus_discount_and_vat_only_when_switched_on(): void
    {
        $b = $this->booking($this->car(), '2026-11-03 10:00', '2026-11-05 10:00', ['extras_amount' => 200, 'discount' => 100]);
        $this->assertSame('2100.00', $b->total);
        $this->assertSame('0.00', $b->tax_amount);

        $this->settings(['vat_enabled' => true, 'vat_rate' => 5, 'vat_inclusive' => false]);
        $b2 = $this->booking($this->car(), '2026-11-03 10:00', '2026-11-05 10:00', ['tax_rate' => 5]);
        $this->assertSame('2100.00', $b2->total);
        $this->assertSame('100.00', $b2->tax_amount);
    }

    /* ---------- create from a lead ---------- */

    public function test_booking_form_prefills_from_lead_and_saves(): void
    {
        $car = $this->car();
        $lead = Lead::create(['name' => 'Lina', 'phone' => '0501234000', 'service_type' => ServiceType::Car, 'interested_in_type' => 'car', 'interested_in_id' => $car->id, 'preferred_date' => '2026-11-10', 'end_date' => '2026-11-12']);

        $page = Livewire::test(CreateBooking::class, ['lead' => (string) $lead->id])
            ->assertFormSet(['lead_id' => $lead->id, 'bookable_id' => $car->id, 'service_type' => 'car', 'base_amount' => 2000.0]);
        $page->call('create')->assertHasNoFormErrors();

        $b = Booking::first();
        $this->assertSame($lead->id, $b->lead_id);
        $this->assertNotNull($b->customer_id, 'customer created from the lead');
        $this->assertSame($b->customer_id, $lead->fresh()->customer_id);
        $this->assertSame(CarStatus::Reserved, $car->fresh()->status);
        $this->assertSame('confirmed', $lead->fresh()->stage);
    }

    /* ---------- conflicts ---------- */

    public function test_overlapping_booking_is_refused_in_the_form_with_a_clear_message(): void
    {
        $car = $this->car();
        $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');

        Livewire::test(CreateBooking::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'service_type' => 'car', 'bookable_id' => $car->id, 'drive_mode' => 'self_drive', 'start_at' => '2026-11-07 10:00', 'end_at' => '2026-11-09 10:00', 'status' => 'confirmed', 'base_amount' => 2000])
            ->call('create')
            ->assertHasFormErrors(['end_at']);
        $this->assertSame(1, Booking::count());
    }

    public function test_overlap_is_refused_on_the_server_even_without_the_form(): void
    {
        $car = $this->car();
        $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');
        $this->expectException(ValidationException::class);
        $this->booking($car, '2026-11-06 10:00', '2026-11-07 10:00');
    }

    public function test_back_to_back_is_fine_and_gap_setting_is_respected(): void
    {
        $car = $this->car();
        $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');
        $this->booking($car, '2026-11-08 10:00', '2026-11-09 10:00');
        $this->assertSame(2, Booking::count());

        $this->settings(['booking_buffer_minutes' => 120]);
        $this->assertNotNull(Availability::assetConflict('car', $car->id, '2026-11-09 11:00', '2026-11-10 10:00'), '2h gap needed after the previous return');
        $this->assertNull(Availability::assetConflict('car', $car->id, '2026-11-09 12:00', '2026-11-10 10:00'));
    }

    public function test_pending_payment_holds_unless_switched_off_and_inquiries_never_hold(): void
    {
        $car = $this->car();
        $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00', ['status' => BookingStatus::PendingPayment]);
        $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00', ['status' => BookingStatus::Inquiry]);
        $this->assertNotNull(Availability::assetConflict('car', $car->id, '2026-11-06 10:00', '2026-11-07 10:00'));

        $this->settings(['pending_payment_holds' => false]);
        $this->assertNull(Availability::assetConflict('car', $car->id, '2026-11-06 10:00', '2026-11-07 10:00'));
    }

    public function test_changing_dates_rechecks_availability_and_never_moves_the_other_booking(): void
    {
        $car = $this->car();
        $first = $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');
        $second = $this->booking($car, '2026-11-10 10:00', '2026-11-12 10:00');

        Livewire::test(EditBooking::class, ['record' => $second->id])
            ->fillForm(['start_at' => '2026-11-07 10:00'])
            ->call('save')
            ->assertHasFormErrors(['end_at']);

        $this->assertSame('2026-11-10 10:00', $second->fresh()->start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-05 10:00', $first->fresh()->start_at->format('Y-m-d H:i'));
    }

    public function test_cancelled_booking_releases_the_car(): void
    {
        $car = $this->car();
        $b = $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');
        $this->assertSame(CarStatus::Reserved, $car->fresh()->status);

        $this->actingAs($this->user('Manager'));
        Livewire::test(EditBooking::class, ['record' => $b->id])
            ->callAction('cancelBooking', data: ['status' => 'cancelled', 'cancel_reason' => 'Customer changed plans'])
            ->assertHasNoActionErrors();

        $this->assertSame(BookingStatus::Cancelled, $b->fresh()->status);
        $this->assertSame(CarStatus::Available, $car->fresh()->status);
        $this->assertNull(Availability::assetConflict('car', $car->id, '2026-11-05 10:00', '2026-11-08 10:00'));
    }

    public function test_maintenance_blocks_new_bookings_unless_a_manager_overrides_and_it_is_logged(): void
    {
        $car = $this->car(['status' => CarStatus::Maintenance]);
        $data = ['customer_id' => $this->customer()->id, 'service_type' => 'car', 'bookable_id' => $car->id, 'drive_mode' => 'self_drive', 'start_at' => '2026-11-07 10:00', 'end_at' => '2026-11-09 10:00', 'status' => 'confirmed', 'base_amount' => 2000];

        Livewire::test(CreateBooking::class)->fillForm($data)->call('create')->assertHasFormErrors(['end_at']);

        $this->actingAs($this->user('Manager'));
        Livewire::test(CreateBooking::class)->fillForm($data + ['override_availability' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Booking::count());
        $this->assertTrue(AuditLog::where('event', 'override')->exists());
    }

    public function test_putting_a_car_into_maintenance_with_bookings_ahead_warns_and_keeps_them(): void
    {
        $car = $this->car();
        $b = $this->booking($car, '2026-11-05 10:00', '2026-11-08 10:00');
        $car->update(['status' => CarStatus::Maintenance]);

        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
        $this->assertTrue(AuditLog::where('event', 'conflict')->where('auditable_id', $car->id)->exists());
    }

    public function test_late_return_keeps_the_car_busy(): void
    {
        $car = $this->car();
        $out = $this->booking($car, '2026-10-30 10:00', '2026-11-01 10:00');
        $out->update(['status' => BookingStatus::InProgress]); // due back yesterday, not returned

        $msg = Availability::assetConflict('car', $car->id, '2026-11-02 08:30', '2026-11-03 10:00'); // starts before now (09:00) while still out
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Still out', $msg);

        // A booking for later is allowed, but its handover is blocked while the car is still out.
        $next = $this->booking($car, '2026-11-04 10:00', '2026-11-05 10:00');
        $this->assertNotEmpty(\App\Filament\Resources\BookingResource\BookingActions::handoverBlockers($next));
    }

    /* ---------- drivers ---------- */

    public function test_driver_conflicts_are_blocked_and_usual_driver_is_listed_first(): void
    {
        $driver = $this->user('Driver', ['name' => 'Imran']);
        $other = $this->user('Driver', ['name' => 'Aaron']);
        $car = $this->car();
        $car->drivers()->attach($driver->id);

        $options = \App\Filament\Resources\BookingResource\BookingActions::driverOptions($car->id);
        $this->assertSame($driver->id, array_key_first($options));
        $this->assertStringContainsString('usual driver', $options[$driver->id]);

        $this->booking($this->car(), '2026-11-05 10:00', '2026-11-06 10:00', ['drive_mode' => DriveMode::WithDriver, 'driver_id' => $driver->id]);
        Livewire::test(CreateBooking::class)
            ->fillForm(['customer_id' => $this->customer()->id, 'service_type' => 'car', 'bookable_id' => $car->id, 'drive_mode' => 'with_driver', 'start_at' => '2026-11-05 12:00', 'end_at' => '2026-11-05 18:00', 'status' => 'confirmed', 'base_amount' => 1500])
            ->fillForm(['driver_id' => $driver->id])
            ->call('create')
            ->assertHasFormErrors(['driver_id']);

        $inactive = $this->user('Driver', ['name' => 'Off Duty', 'is_active' => false]);
        $this->assertArrayNotHasKey($inactive->id, \App\Filament\Resources\BookingResource\BookingActions::driverOptions());
        $this->assertArrayHasKey($other->id, \App\Filament\Resources\BookingResource\BookingActions::driverOptions());
    }

    public function test_usual_driver_is_suggested_when_with_driver_is_chosen(): void
    {
        $driver = $this->user('Driver');
        $car = $this->car();
        $car->drivers()->attach($driver->id);

        Livewire::test(CreateBooking::class)
            ->fillForm(['service_type' => 'car', 'start_at' => '2026-11-05 10:00', 'end_at' => '2026-11-05 18:00'])
            ->fillForm(['bookable_id' => $car->id])
            ->fillForm(['drive_mode' => 'with_driver'])
            ->assertFormSet(['driver_id' => $driver->id]);
    }

    /* ---------- yachts & trips ---------- */

    public function test_yacht_charter_overlap_and_trip_departure_clash(): void
    {
        $yacht = $this->yacht();
        $this->booking($yacht, '2026-11-05 10:00', '2026-11-05 14:00');
        $this->assertNotNull(Availability::assetConflict('yacht', $yacht->id, '2026-11-05 13:00', '2026-11-05 16:00'));

        $trip = $this->trip($yacht);
        $this->tripDate($trip, '2026-11-06');
        $clash = Availability::assetConflict('yacht', $yacht->id, '2026-11-06 16:00', '2026-11-06 20:00');
        $this->assertNotNull($clash);
        $this->assertStringContainsString('trip', $clash);
        $this->assertSame(YachtStatus::Booked, $yacht->fresh()->status);
    }

    public function test_trip_seats_cannot_be_overbooked(): void
    {
        $trip = $this->trip(null, ['guest_capacity' => 10]);
        $date = $this->tripDate($trip, '2026-11-06');
        $start = '2026-11-06 17:30';
        $end = '2026-11-06 19:30';

        $this->booking($trip, $start, $end, ['trip_date_id' => $date->id, 'guests' => 8]);
        $this->assertSame(2, Availability::seatsLeft($date->fresh()));

        try {
            $this->booking($trip, $start, $end, ['trip_date_id' => $date->id, 'guests' => 3]);
            $this->fail('3 guests should not fit in 2 seats');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2 seat', collect($e->errors())->flatten()->first());
        }

        $b = $this->booking($trip, $start, $end, ['trip_date_id' => $date->id, 'guests' => 2]);
        $this->assertSame('600.00', $b->total, '2 guests × AED 300 per person');
        $this->assertSame(0, Availability::seatsLeft($date->fresh()));
    }
}
