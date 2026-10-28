<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Models\Booking;
use App\Models\PriceExtra;
use App\Models\PriceRule;
use App\Models\Quotation;
use App\Support\Pricing\PriceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 2: price list, special rates, extras, agent changes. */
class PricingTest extends TestCase
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

    protected function price($car, string $start, string $end, array $more = []): array
    {
        return PriceEngine::calculate($more + ['service_type' => 'car', 'item' => $car, 'start_at' => $start, 'end_at' => $end, 'drive_mode' => 'self_drive']);
    }

    protected function quote($car, array $attrs = []): Quotation
    {
        return Quotation::create($attrs + [
            'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'customer_id' => $this->customer()->id,
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-13 10:00', 'valid_until' => '2026-11-08',
        ]);
    }

    /* ---------- engine ---------- */

    public function test_standard_rates_are_used_when_there_is_no_special_rate(): void
    {
        $r = $this->price($this->car(), '2026-11-10 10:00', '2026-11-13 10:00');
        $this->assertSame(['day', 3, 1000.0, 3000.0], [$r['rate_type'], $r['quantity'], $r['rate'], $r['base_amount']]);
        $this->assertSame(2000.0, $r['deposit']);
        $this->assertNull($r['rule']);
    }

    public function test_a_season_replaces_the_daily_rate_only_inside_its_dates(): void
    {
        $car = $this->car();
        PriceRule::create(['name' => 'New Year week', 'service_type' => 'car', 'applies_to' => 'item', 'item_id' => $car->id,
            'unit' => 'day', 'method' => 'set', 'amount' => 1500, 'starts_on' => '2026-12-28', 'ends_on' => '2027-01-03']);

        $in = $this->price($car, '2026-12-30 10:00', '2027-01-01 10:00');
        $this->assertSame(1500.0, $in['rate']);
        $this->assertSame(3000.0, $in['base_amount']);
        $this->assertSame('New Year week', $in['rule']['name']);
        $this->assertSame(1000.0, $in['standard_rate']);

        $out = $this->price($car, '2026-12-20 10:00', '2026-12-22 10:00');
        $this->assertSame(1000.0, $out['rate']);
    }

    public function test_item_rate_beats_category_and_priority_beats_both(): void
    {
        $car = $this->car(['type' => 'SUV']);
        PriceRule::create(['name' => 'SUV summer', 'service_type' => 'car', 'applies_to' => 'category', 'category' => 'SUV', 'unit' => 'any', 'method' => 'percent', 'amount' => 20]);
        $this->assertSame(1200.0, $this->price($car, '2026-11-10 10:00', '2026-11-11 10:00')['rate']);

        PriceRule::create(['name' => 'This car', 'service_type' => 'car', 'applies_to' => 'item', 'item_id' => $car->id, 'unit' => 'day', 'method' => 'percent', 'amount' => -10]);
        $this->assertSame(900.0, $this->price($car, '2026-11-10 10:00', '2026-11-11 10:00')['rate']);

        PriceRule::create(['name' => 'All cars promo', 'service_type' => 'car', 'applies_to' => 'all', 'unit' => 'day', 'method' => 'set', 'amount' => 777, 'priority' => 5]);
        $this->assertSame(777.0, $this->price($car, '2026-11-10 10:00', '2026-11-11 10:00')['rate']);

        // A rule for another type or a switched-off rule does nothing.
        PriceRule::query()->update(['is_active' => false]);
        $this->assertSame(1000.0, $this->price($car, '2026-11-10 10:00', '2026-11-11 10:00')['rate']);
    }

    public function test_weekday_rate_and_minimum_while_it_applies(): void
    {
        $car = $this->car();
        PriceRule::create(['name' => 'Weekend', 'service_type' => 'car', 'applies_to' => 'all', 'unit' => 'day', 'method' => 'set', 'amount' => 1300,
            'weekdays' => [5, 6], 'min_units' => 2]);

        $friday = $this->price($car, '2026-11-13 10:00', '2026-11-14 10:00'); // Friday, one day
        $this->assertSame(1300.0, $friday['rate']);
        $this->assertSame(2, $friday['quantity'], 'charged for the minimum 2 days');
        $this->assertNotEmpty($friday['notes']);

        $monday = $this->price($car, '2026-11-09 10:00', '2026-11-10 10:00');
        $this->assertSame(1000.0, $monday['rate']);
        $this->assertSame(1, $monday['quantity']);
    }

    public function test_driver_rates_only_take_driver_rules(): void
    {
        $car = $this->car();
        PriceRule::create(['name' => 'Chauffeur promo', 'service_type' => 'car', 'applies_to' => 'all', 'drive_mode' => 'with_driver', 'unit' => 'day', 'method' => 'set', 'amount' => 1500]);

        $this->assertSame(1500.0, $this->price($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['drive_mode' => 'with_driver'])['rate']);
        $this->assertSame(1000.0, $this->price($car, '2026-11-10 10:00', '2026-11-12 10:00')['rate']);
    }

    public function test_delivery_collection_fees_and_extras(): void
    {
        $car = $this->car(['delivery_fee' => 100, 'addons' => [['name' => 'Child seat', 'price' => 30, 'unit' => 'per_day']]]);
        $collect = PriceExtra::create(['name' => 'Collection in Dubai', 'kind' => 'collection', 'amount' => 80, 'service_types' => ['car']]);
        $fee = PriceExtra::create(['name' => 'Cleaning', 'kind' => 'fee', 'amount' => 50, 'service_types' => ['car']]);
        $driver = PriceExtra::create(['name' => 'Additional driver', 'kind' => 'extra', 'amount' => 40, 'unit' => 'per_day']);
        PriceExtra::create(['name' => 'Yacht DJ', 'kind' => 'fee', 'amount' => 999, 'service_types' => ['yacht']]);

        $r = $this->price($car, '2026-11-10 10:00', '2026-11-13 10:00', [
            'delivery_method' => 'delivery', 'return_method' => 'collection', 'extra_keys' => ['addon:child-seat', 'extra:'.$driver->id],
        ]);
        $lines = collect($r['lines'])->keyBy('key');
        $this->assertSame(100.0, $lines['delivery']['amount'], "the car's own delivery fee");
        $this->assertSame(80.0, $lines['extra:'.$collect->id]['amount']);
        $this->assertSame(50.0, $lines['extra:'.$fee->id]['amount'], 'always added');
        $this->assertSame(90.0, $lines['addon:child-seat']['amount'], '3 days × 30');
        $this->assertSame(120.0, $lines['extra:'.$driver->id]['amount'], '3 days × 40');
        $this->assertCount(5, $lines, 'the yacht-only fee is not added');
        $this->assertSame(3440.0, $r['standard_amount']);

        // Customer collects and returns: no delivery or collection lines.
        $office = $this->price($car, '2026-11-10 10:00', '2026-11-13 10:00', ['delivery_method' => 'office', 'return_method' => 'office']);
        $this->assertSame(['extra:'.$fee->id], collect($office['lines'])->pluck('key')->all());
    }

    public function test_yacht_trip_and_package_are_priced_by_the_engine(): void
    {
        $yacht = $this->yacht();
        $this->assertSame(['half_day', 3200.0], [($r = PriceEngine::calculate(['service_type' => 'yacht', 'item' => $yacht, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 14:00']))['rate_type'], $r['base_amount']]);
        $this->assertSame(['hour', 2700.0], [($r = PriceEngine::calculate(['service_type' => 'yacht', 'item' => $yacht, 'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-10 13:00']))['rate_type'], $r['base_amount']]);

        $trip = $this->trip(null, ['price' => 250, 'price_unit' => 'per_person']);
        $this->assertSame(1000.0, PriceEngine::calculate(['service_type' => 'trip', 'item' => $trip, 'start_at' => '2026-11-10 18:00', 'end_at' => '2026-11-10 20:00', 'guests' => 4])['base_amount']);
    }

    /* ---------- quotations and bookings ---------- */

    public function test_the_server_works_the_price_out_again(): void
    {
        $car = $this->car(['delivery_fee' => 100]);
        $q = $this->quote($car, ['delivery_method' => 'delivery', 'base_amount' => 3000]);
        $this->assertSame('3100.00', $q->total);
        $this->assertSame('3100.00', $q->standard_amount);
        $this->assertFalse($q->isAdjusted());
        $this->assertSame('delivery', $q->extras[0]['key']);
        $this->assertSame(3000.0, (float) $q->price_breakdown['base_amount']);

        // Dates change: the price follows the list (no hand change was made).
        $q->update(['end_at' => '2026-11-15 10:00']);
        $this->assertSame('5000.00', $q->base_amount);
        $this->assertSame('5100.00', $q->total);
    }

    public function test_a_changed_price_needs_a_reason_and_the_permission(): void
    {
        $car = $this->car();
        try {
            $this->quote($car, ['base_amount' => 2500]);
            $this->fail('changed price saved without a reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.adjustment_reason', $e->errors());
        }

        $q = $this->quote($car, ['base_amount' => 2500, 'adjustment_reason' => 'Matched a competitor']);
        $this->assertSame('2500.00', $q->total);
        $this->assertSame('3000.00', $q->standard_amount);
        $this->assertTrue($q->isAdjusted());
        $this->assertSame(-500.0, $q->adjustmentAmount());
        $this->assertSame(auth()->id(), $q->adjusted_by);

        // Operations may not change prices.
        $this->actingAs($this->user('Operations'));
        try {
            $this->quote($car, ['discount' => 200, 'adjustment_reason' => 'Friend']);
            $this->fail('operations gave a discount');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.base_amount', $e->errors());
        }
    }

    public function test_waiving_a_fee_is_a_change_with_a_reason(): void
    {
        $car = $this->car(['delivery_fee' => 100]);
        $q = $this->quote($car, ['delivery_method' => 'delivery', 'adjustment_reason' => 'VIP',
            'extras' => [['key' => 'delivery', 'label' => 'Delivery', 'amount' => 0, 'standard' => 100, 'waived' => true]]]);
        $this->assertSame('3000.00', $q->total);
        $this->assertSame('3100.00', $q->standard_amount);
        $this->assertTrue($q->extras[0]['waived']);
    }

    public function test_max_discount_setting_limits_agents_but_not_managers(): void
    {
        $this->settings(['price_max_discount_percent' => 10]);
        $car = $this->car();
        try {
            $this->quote($car, ['discount' => 600, 'adjustment_reason' => 'Big group']);
            $this->fail('more than 10% off');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.discount', $e->errors());
        }
        $this->assertSame('2700.00', $this->quote($car, ['discount' => 300, 'adjustment_reason' => 'Big group'])->total);

        $this->actingAs($this->user('Manager'));
        $this->assertSame('2400.00', $this->quote($car, ['discount' => 600, 'adjustment_reason' => 'Big group'])->total);
    }

    public function test_an_accepted_quote_keeps_its_price_when_the_rates_change(): void
    {
        $car = $this->car();
        $q = $this->quote($car, ['discount' => 100, 'adjustment_reason' => 'Loyal']);
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        $q->update(['status' => QuotationStatus::Accepted, 'accepted_at' => now()]);

        $car->update(['daily_price' => 2000]);
        PriceRule::create(['name' => 'Peak', 'service_type' => 'car', 'applies_to' => 'all', 'unit' => 'day', 'method' => 'percent', 'amount' => 50]);
        $this->assertSame('2900.00', $q->fresh()->total);

        $booking = Booking::create([
            'status' => BookingStatus::Confirmed, 'customer_id' => $q->customer_id, 'quotation_id' => $q->id,
            'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => $q->start_at, 'end_at' => $q->end_at,
            'base_amount' => $q->base_amount, 'extras' => $q->extras, 'extras_amount' => $q->extras_amount, 'discount' => $q->discount,
            'standard_amount' => $q->standard_amount, 'adjustment_reason' => $q->adjustment_reason, 'price_breakdown' => $q->price_breakdown,
        ]);
        $this->assertSame('2900.00', $booking->total, 'the booking keeps the agreed price, not today\'s rates');
    }

    public function test_moving_the_dates_of_a_discounted_booking_needs_no_price_permission(): void
    {
        $car = $this->car();
        $b = $this->booking($car, '2026-11-10 10:00', '2026-11-12 10:00', ['discount' => 100, 'adjustment_reason' => 'Corporate']);
        $this->assertSame('1900.00', $b->total);

        $this->actingAs($this->user('Operations'));
        $b->update(['end_at' => '2026-11-13 10:00']);
        $this->assertSame('2900.00', $b->fresh()->total, 'price follows the list, the same discount stays');
    }

    public function test_old_bookings_keep_their_typed_add_on_amount(): void
    {
        $car = $this->car();
        $b = $this->booking($car, '2026-11-10 10:00', '2026-11-12 10:00');
        \Illuminate\Support\Facades\DB::table('bookings')->where('id', $b->id)->update(['extras_amount' => 150, 'extras' => null, 'standard_amount' => null, 'price_breakdown' => null]);

        $b->fresh()->update(['end_at' => '2026-11-13 10:00', 'adjustment_reason' => null]);
        $b = $b->fresh();
        $this->assertSame('150.00', $b->extras_amount);
        $this->assertSame('Add-ons & delivery', $b->extras[0]['label']);
        $this->assertSame('3150.00', $b->total);
    }

    /* ---------- screens ---------- */

    public static function pricingScreens(): array
    {
        return [
            'admin rate card' => ['Admin', '/crm/rate-card', 200],
            'admin yacht rates' => ['Admin', '/crm/rate-card?service=yacht', 200],
            'admin trip rates' => ['Admin', '/crm/rate-card?service=trip', 200],
            'admin package rates' => ['Admin', '/crm/rate-card?service=package', 200],
            'admin special rates' => ['Admin', '/crm/price-rules', 200],
            'admin extras' => ['Admin', '/crm/price-extras', 200],
            'admin price check' => ['Admin', '/crm/price-check', 200],
            'agent price check' => ['Agent', '/crm/price-check', 200],
            'agent rate card' => ['Agent', '/crm/rate-card', 200],
            'agent no special rates' => ['Agent', '/crm/price-rules', 403],
            'operations no rate card' => ['Operations', '/crm/rate-card', 403],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pricingScreens')]
    public function test_pricing_screens_open_and_follow_permissions(string $role, string $url, int $status): void
    {
        $this->car();
        $this->yacht();
        $this->actingAs($this->user($role));
        $this->get($url)->assertStatus($status);
    }

    public function test_rate_card_edits_the_standard_rate_in_place(): void
    {
        $car = $this->car();
        $this->actingAs($this->user('Admin'));
        \Livewire\Livewire::test(\App\Filament\Pages\RateCard::class)
            ->call('updateTableColumnState', 'daily_price', (string) $car->id, '1111');
        $this->assertSame('1111.00', $car->fresh()->daily_price);
    }

    public function test_quotation_form_builds_lines_from_the_price_list(): void
    {
        $car = $this->car(['delivery_fee' => 120]);
        $extra = PriceExtra::create(['name' => 'Additional driver', 'kind' => 'extra', 'amount' => 40, 'unit' => 'per_day']);
        $customer = $this->customer();

        $lw = \Livewire\Livewire::test(\App\Filament\Resources\QuotationResource\Pages\CreateQuotation::class)
            ->fillForm(['customer_id' => $customer->id, 'service_type' => 'car', 'bookable_id' => $car->id])
            ->fillForm(['start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00'])
            ->fillForm(['delivery_method' => 'delivery', 'extra_keys' => ['extra:'.$extra->id], 'valid_until' => '2026-11-08']);
        $lw->assertFormSet(['base_amount' => 2000.0, 'standard_amount' => 2200.0]);
        $lw->call('create')->assertHasNoFormErrors();

        $q = Quotation::latest('id')->first();
        $this->assertSame('2200.00', $q->total);
        $this->assertEqualsCanonicalizing(['delivery', 'extra:'.$extra->id], collect($q->extras)->pluck('key')->all());

        // A lower price without a reason is refused by the form too.
        \Livewire\Livewire::test(\App\Filament\Resources\QuotationResource\Pages\EditQuotation::class, ['record' => $q->id])
            ->fillForm(['base_amount' => 1500])
            ->call('save')
            ->assertHasFormErrors(['adjustment_reason' => 'required']);
    }
}
