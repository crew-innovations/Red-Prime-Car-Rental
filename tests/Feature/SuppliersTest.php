<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Car;
use App\Models\Supplier;
use App\Models\SupplierRate;
use App\Models\Transaction;
use App\Support\Accounts\ProfitReport;
use App\Support\BookingFlow;
use App\Support\Suppliers\BookingProfit;
use App\Support\Suppliers\SupplierCost;
use App\Support\Suppliers\SupplierLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Brief 29 Oct, Modules 2–3: suppliers, cost rates, booking cost snapshots, supplier bills, payments, profit. */
class SuppliersTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected Supplier $supplier;

    protected Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Manager'));
        $this->supplier = Supplier::create(['name' => 'Al Noor Cars', 'type' => 'rental_company', 'status' => 'active', 'opening_balance' => 1000]);
        $this->car = $this->car(['ownership' => 'partner', 'supplier_id' => $this->supplier->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function rate(array $attrs): SupplierRate
    {
        return $this->supplier->rates()->create($attrs + ['method' => 'per_day', 'amount' => 400]);
    }

    /** Car of 1000/day: 10 Nov 10:00 → 13 Nov 10:00 = 3 days, AED 3,000. */
    protected function threeDays(array $attrs = []): Booking
    {
        return $this->booking($this->car, '2026-11-10 10:00', '2026-11-13 10:00', $attrs);
    }

    protected function complete(Booking $b): Booking
    {
        BookingFlow::deliver($b->fresh(), ['delivered_at' => now(), 'delivery_km' => 10000]);
        BookingFlow::receive($b->fresh(), ['returned_at' => now()->addDays(3), 'return_km' => 10450, 'return_fuel' => 'full', 'return_condition' => 'good']);

        return $b->fresh();
    }

    public function test_missing_cost_blocks_confirmation_never_zero(): void
    {
        try {
            $this->threeDays();
            $this->fail('No rate: confirming must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Supplier cost not set', collect($e->errors())->flatten()->first());
        }
        $inquiry = $this->threeDays(['status' => BookingStatus::Inquiry]);
        $this->assertSame('missing', $inquiry->supplier_cost_status);
        $this->assertNull($inquiry->supplier_cost); // never assumed to be zero

        // Our own car needs no supplier cost.
        $own = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-11 10:00');
        $this->assertNull($own->supplier_cost_status);
        $this->assertNull($own->supplier_id);

        // Finance sets the cost for this booking: then it can be confirmed; Sales can't do that.
        $this->actingAs($this->user('Sales'));
        try {
            SupplierCost::override($inquiry, 900, 'Owner agreed');
            $this->fail('Sales must not override');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($this->user('Finance'));
        SupplierCost::override($inquiry->fresh(), 900, 'Owner agreed a weekend price');
        $inquiry->refresh();
        $this->assertSame('override', $inquiry->supplier_cost_status);
        $this->assertSame('missing', data_get($inquiry->supplier_cost_snapshot, 'override.calculated_status'));
        $inquiry->update(['status' => BookingStatus::Confirmed]);
        $this->assertEqualsWithDelta(900, (float) $inquiry->fresh()->supplier_cost, 0.001);
        $this->assertTrue(\App\Models\AuditLog::where('auditable_id', $inquiry->id)->where('event', 'override')->where('note', 'like', 'Supplier cost set%')->exists());
    }

    public function test_vehicle_rate_wins_over_default_and_dates_pick_the_rate(): void
    {
        $this->rate(['amount' => 300]); // supplier default
        $b = $this->threeDays();
        $this->assertSame('supplier', data_get($b->supplier_cost_snapshot, 'source'));
        $this->assertEqualsWithDelta(900, (float) $b->supplier_cost, 0.001);

        $this->rate(['vehicle_type' => 'car', 'vehicle_id' => $this->car->id, 'amount' => 450, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $this->rate(['vehicle_type' => 'car', 'vehicle_id' => $this->car->id, 'amount' => 500, 'starts_on' => '2026-12-01']);
        $nov = $this->booking($this->car, '2026-11-20 10:00', '2026-11-23 10:00');
        $this->assertSame('vehicle', data_get($nov->supplier_cost_snapshot, 'source'));
        $this->assertEqualsWithDelta(1350, (float) $nov->supplier_cost, 0.001);
        $dec = $this->booking($this->car, '2026-12-05 10:00', '2026-12-07 10:00');
        $this->assertEqualsWithDelta(1000, (float) $dec->supplier_cost, 0.001);

        // Overlapping active rates are refused; a vehicle of another supplier too.
        try {
            $this->rate(['vehicle_type' => 'car', 'vehicle_id' => $this->car->id, 'amount' => 999, 'starts_on' => '2026-11-15', 'ends_on' => '2026-11-20']);
            $this->fail('overlap');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.starts_on', $e->errors());
        }
        try {
            $this->rate(['vehicle_type' => 'car', 'vehicle_id' => $this->car()->id, 'amount' => 1]);
            $this->fail('foreign vehicle');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.vehicle_id', $e->errors());
        }
    }

    public function test_each_cost_method(): void
    {
        $cases = [
            ['per_booking', 250, null, 250],
            ['per_day', 400, null, 1200],
            ['per_week', 2100, 'pro_rata', 900],        // 3 days ÷ 7 × 2100
            ['per_week', 2100, 'full', 2100],
            ['per_month', 6000, 'pro_rata', 600],       // 3 ÷ 30 × 6000
            ['percent', 40, null, 1200],                // 40% of AED 3,000 revenue without VAT
        ];
        foreach ($cases as [$method, $amount, $partial, $expected]) {
            SupplierRate::query()->delete();
            $this->rate(['method' => $method, 'amount' => $amount, 'partial' => $partial ?? 'pro_rata']);
            $b = $this->threeDays(['status' => BookingStatus::Inquiry]);
            $this->assertEqualsWithDelta($expected, (float) $b->supplier_cost, 0.01, $method.' '.$partial);
            $b->forceDelete();
        }

        // Per km on a rental: known after the odometer is read at return.
        SupplierRate::query()->delete();
        $this->rate(['method' => 'per_km', 'amount' => 2]);
        $b = $this->threeDays();
        $this->assertSame('pending', $b->supplier_cost_status);
        $done = $this->complete($b);
        $this->assertSame('calculated', $done->supplier_cost_status);
        $this->assertEqualsWithDelta(900, (float) $done->supplier_cost, 0.001); // 450 km × 2
    }

    public function test_snapshot_is_kept_and_used_rates_are_locked(): void
    {
        $rate = $this->rate(['amount' => 400]);
        $b = $this->threeDays();
        $this->assertEqualsWithDelta(1200, (float) $b->supplier_cost, 0.001);

        try {
            $rate->update(['amount' => 500]);
            $this->fail('used rate edited');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.amount', $e->errors());
        }
        // The supplier's new price from next month: end the old rate, add a new one.
        $rate->refresh()->update(['ends_on' => '2026-11-30']);
        $this->rate(['amount' => 500, 'starts_on' => '2026-12-01']);
        $b->update(['notes' => 'VIP']);
        $this->assertEqualsWithDelta(1200, (float) $b->fresh()->supplier_cost, 0.001);
        $this->assertSame($rate->id, data_get($b->fresh()->supplier_cost_snapshot, 'rate_id'));
    }

    public function test_completion_posts_one_bill_and_edits_do_not_duplicate(): void
    {
        $this->rate(['amount' => 400]);
        $b = $this->complete($this->threeDays());

        $bills = Transaction::where('booking_id', $b->id)->where('supplier_id', $this->supplier->id)->get();
        $this->assertCount(1, $bills);
        $bill = $bills->first();
        $this->assertSame(TransactionType::Expense, $bill->type);
        $this->assertSame('partner_payout', $bill->category);
        $this->assertSame(PaymentStatus::Pending, $bill->status);
        $this->assertEqualsWithDelta(1200, (float) $bill->amount, 0.001);
        $this->assertSame($bill->id, $b->supplier_bill_id);

        SupplierLedger::postForBooking($b->fresh());
        $b->update(['notes' => 'Edited after the job']);
        $this->assertSame(1, Transaction::where('booking_id', $b->id)->where('supplier_id', $this->supplier->id)->count());
        try {
            SupplierLedger::addBill($this->supplier, 50, '2026-11-14', 'Extra', $b->id);
            $this->fail('second bill for the same booking');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.booking_id', $e->errors());
        }
        // Frozen: an override after the bill is refused.
        try {
            SupplierCost::override($b->fresh(), 10, 'late');
            $this->fail('override after bill');
        } catch (ValidationException) {
        }
    }

    public function test_payments_balance_statement_and_no_double_counting(): void
    {
        $this->rate(['amount' => 400]);
        $b = $this->complete($this->threeDays());
        $bill = Transaction::find($b->supplier_bill_id);
        $this->assertSame(['opening' => 1000.0, 'bills' => 1200.0, 'payments' => 0.0, 'balance' => 2200.0, 'waiting_approval' => 0.0], SupplierLedger::balance($this->supplier));

        // Payments: suggested oldest first (opening balance, then the bill).
        $this->assertSame(['opening' => 1000.0, $bill->id => 500.0], SupplierLedger::suggest($this->supplier, 1500));
        $p = SupplierLedger::pay($this->supplier, 1500, '2026-11-02', ['opening' => 1000, $bill->id => 500], 'bank_transfer', 'TT-1');
        $this->assertSame(PaymentStatus::PartiallyPaid, $bill->fresh()->status);
        $this->assertEqualsWithDelta(700, SupplierLedger::outstanding($bill->fresh()), 0.001);
        try {
            SupplierLedger::pay($this->supplier, 1500, '2026-11-02', ['opening' => 1000, $bill->id => 500], 'bank_transfer', 'TT-1');
            $this->fail('duplicate submission');
        } catch (ValidationException) {
        }
        try {
            SupplierLedger::pay($this->supplier, 800, '2026-11-02', [$bill->id => 800]);
            $this->fail('more than owed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('700.00', collect($e->errors())->flatten()->first());
        }
        SupplierLedger::pay($this->supplier, 700, '2026-11-02', [$bill->id => 700]);
        $this->assertSame(PaymentStatus::Paid, $bill->fresh()->status);
        $this->assertEqualsWithDelta(0, SupplierLedger::balance($this->supplier)['balance'], 0.001);

        // Paying a bill is not a second cost: the period's expenses hold the bill once.
        $expenses = Transaction::totals(Transaction::query()->whereBetween('date', ['2026-11-01', '2026-11-30']))['expenses'];
        $this->assertEqualsWithDelta(1200, $expenses, 0.001);

        // The bill's status only follows payments; reversing a paid bill is refused.
        try {
            $bill->fresh()->update(['status' => PaymentStatus::Pending]);
            $this->fail('manual status change');
        } catch (ValidationException) {
        }
        try {
            $bill->fresh()->reverse('wrong');
            $this->fail('reverse a bill with payments');
        } catch (ValidationException) {
        }

        // Reverse the first payment: owed again; statement agrees with the balance.
        SupplierLedger::reverse($p, 'Bank returned it');
        $this->assertSame(PaymentStatus::PartiallyPaid, $bill->fresh()->status);
        $balance = SupplierLedger::balance($this->supplier)['balance'];
        $this->assertEqualsWithDelta(1500, $balance, 0.001);
        $st = SupplierLedger::statement($this->supplier);
        $this->assertEqualsWithDelta($balance, $st['closing'], 0.001);
        $this->assertSame(['bill', 'payment'], collect($st['lines'])->pluck('kind')->all());
        $this->assertEqualsWithDelta($balance, SupplierLedger::totalOwed(), 0.001);
    }

    public function test_bill_waiting_for_approval_and_who_can_pay(): void
    {
        $this->rate(['amount' => 400]);
        $b = $this->threeDays();
        $this->actingAs($this->user('Operations')); // completes the job; expenses from Operations wait for approval
        $b = $this->complete($b);
        $bill = Transaction::find($b->supplier_bill_id);
        $this->assertSame('pending', $bill->approval_status);

        $this->actingAs($this->user('Finance'));
        $this->assertSame(1200.0, SupplierLedger::balance($this->supplier)['waiting_approval']);
        $this->assertEqualsWithDelta(1000, SupplierLedger::balance($this->supplier)['balance'], 0.001); // not owed until approved
        try {
            SupplierLedger::pay($this->supplier, 100, '2026-11-02', [$bill->id => 100]);
            $this->fail('pay a bill waiting for approval');
        } catch (ValidationException) {
        }
        $this->assertNull(BookingProfit::for($b)['actual']);
        $bill->approve();
        $this->assertEqualsWithDelta(1800, BookingProfit::for($b->fresh())['actual'], 0.001);

        $this->actingAs($this->user('Sales'));
        try {
            SupplierLedger::pay($this->supplier, 100, '2026-11-02', [$bill->id => 100]);
            $this->fail('Sales must not pay suppliers');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->get('/crm/suppliers')->assertForbidden();
    }

    public function test_booking_profit_estimated_then_actual_and_reports(): void
    {
        $this->rate(['amount' => 400]);
        $b = $this->threeDays();
        $b->transactions()->create(['type' => TransactionType::Expense, 'category' => 'fuel', 'date' => today(), 'amount' => 100, 'status' => PaymentStatus::Paid, 'description' => 'Fuel top-up']);

        $p = BookingProfit::for($b->fresh());
        $this->assertSame(3000.0, $p['revenue']);
        $this->assertSame(1200.0, $p['supplier']);
        $this->assertSame(100.0, $p['other']);
        $this->assertSame(1700.0, $p['estimated']);
        $this->assertSame(56.7, $p['estimated_margin']);
        $this->assertNull($p['actual']);

        $done = $this->complete($b);
        $p = BookingProfit::for($done);
        $this->assertTrue($p['supplier_posted']);
        $this->assertSame(1700.0, $p['actual']);           // the bill replaces the estimate: counted once
        $this->assertSame(1700.0, $p['estimated']);

        $rows = collect(ProfitReport::forRange(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))->rows('supplier'));
        $row = $rows->firstWhere('label', 'Al Noor Cars');
        $this->assertSame(1300.0, $row['costs']);
        $this->assertSame(1700.0, $row['contribution']);
        $this->get('/crm/profit-reports?by=supplier&from=2026-11-01&until=2026-11-30')->assertOk()->assertSee('Al Noor Cars');
        $this->get('/crm/suppliers/'.$this->supplier->id.'/edit')->assertOk()->assertSee('We owe');
        $page = \App\Filament\Resources\SupplierResource\Pages\EditSupplier::class;
        \Livewire\Livewire::test(\App\Filament\Resources\SupplierResource\RelationManagers\RatesRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => $page])
            ->assertOk()->assertSee('Per day')->assertSee('Yes, locked');
        \Livewire\Livewire::test(\App\Filament\Resources\SupplierResource\RelationManagers\BillsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => $page])
            ->assertOk()->assertSee($done->reference);
        \Livewire\Livewire::test(\App\Filament\Resources\SupplierResource\RelationManagers\PaymentsRelationManager::class, ['ownerRecord' => $this->supplier, 'pageClass' => $page])
            ->assertOk();
        $this->get('/crm/bookings/'.$done->id.'/edit')->assertOk()->assertSee('Estimated profit');
    }

    public function test_cross_hire_needs_the_supplier_and_suppliers_with_history_stay(): void
    {
        $other = Supplier::create(['name' => 'Desert Limo', 'status' => 'active']);
        $other->rates()->create(['method' => 'per_booking', 'amount' => 700]);
        $hired = $this->car(['ownership' => 'cross_hire']);
        try {
            $this->booking($hired, '2026-11-10 10:00', '2026-11-11 10:00');
            $this->fail('cross hire without supplier');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Choose the supplier', collect($e->errors())->flatten()->first());
        }
        $b = $this->booking($hired, '2026-11-10 10:00', '2026-11-11 10:00', ['supplier_id' => $other->id]);
        $this->assertEqualsWithDelta(700, (float) $b->supplier_cost, 0.001);

        try {
            $other->delete();
            $this->fail('supplier with bookings deleted');
        } catch (ValidationException) {
        }
        $blocked = Supplier::create(['name' => 'Blocked Co', 'status' => 'blocked']);
        $blocked->rates()->create(['method' => 'per_booking', 'amount' => 1]);
        $this->expectException(ValidationException::class);
        $this->booking($hired, '2026-11-20 10:00', '2026-11-21 10:00', ['supplier_id' => $blocked->id]);
    }
}
