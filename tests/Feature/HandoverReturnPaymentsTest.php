<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CarStatus;
use App\Enums\PaymentStatus;
use App\Enums\Pipeline;
use App\Enums\Readiness;
use App\Enums\TransactionType;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\CarResource\Pages\ListCars;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Transaction;
use App\Support\BookingFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** Checklist 8 (handover), 9 (return & inspection), 10 (payments & accounts), 15 (dashboard money). */
class HandoverReturnPaymentsTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seedRoles();
        $this->actingAs($this->user('Operations'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 3-day self-drive booking, AED 3000, deposit 2000. */
    protected function confirmed(array $attrs = []): Booking
    {
        $lead = Lead::create(['name' => 'Karim', 'phone' => '0503334455', 'pipeline' => Pipeline::Car]);

        return $this->booking($this->car(), '2026-11-05 10:00', '2026-11-08 10:00', $attrs + ['lead_id' => $lead->id]);
    }

    protected function deliver(Booking $b, array $data = [])
    {
        return Livewire::test(EditBooking::class, ['record' => $b->id])->callAction('deliver', data: $data + [
            'delivered_at' => '2026-11-05 10:00', 'delivery_km' => 10000, 'delivery_fuel' => 'full',
            'deposit_received' => 2000, 'deposit_method' => 'card', 'payment_amount' => 1000, 'payment_method' => 'card',
        ]);
    }

    public function test_balance_shows_in_accounts_to_collect(): void
    {
        $b = $this->confirmed();
        $this->assertSame('3000.00', $b->fresh()->balance);
        $row = Transaction::where('booking_id', $b->id)->where('is_balance', true)->first();
        $this->assertSame('3000.00', $row->amount);
        $this->assertSame(PaymentStatus::Pending, $row->status);
    }

    public function test_handover_records_everything_and_marks_the_car_rented(): void
    {
        $b = $this->confirmed();
        $this->deliver($b, ['delivery_notes' => 'Small scratch rear bumper'])->assertHasNoActionErrors();

        $b->refresh();
        $this->assertSame(BookingStatus::InProgress, $b->status);
        $this->assertSame(10000, (int) $b->delivery_km);
        $this->assertSame('full', $b->delivery_fuel);
        $this->assertNotNull($b->delivered_by);
        $this->assertSame(CarStatus::Rented, $b->bookable->fresh()->status);
        $this->assertSame('2000.00', number_format($b->depositHeld(), 2, '.', ''));
        $this->assertSame('1000.00', $b->paid_amount, 'deposit is not counted as rental payment');
        $this->assertSame('2000.00', $b->balance);
        $this->assertSame('vehicle_delivered', $b->lead->fresh()->stage);
    }

    public function test_handover_is_blocked_until_the_car_is_ready_unless_a_manager_overrides(): void
    {
        $b = $this->confirmed();
        $b->bookable->update(['readiness' => Readiness::Cleaning, 'readiness_note' => 'Interior']);

        $this->deliver($b);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);

        $this->actingAs($this->user('Manager'));
        $this->deliver($b, ['override' => true])->assertHasNoActionErrors();
        $this->assertSame(BookingStatus::InProgress, $b->fresh()->status);
        $this->assertTrue(AuditLog::where('event', 'override')->where('auditable_id', $b->id)->exists());
    }

    public function test_return_with_charges_late_flag_inspection_and_deposit(): void
    {
        $this->settings(['late_charge_mode' => 'per_hour', 'late_charge_amount' => 100, 'late_grace_minutes' => 30]);
        $b = $this->confirmed();
        $this->deliver($b);
        Carbon::setTestNow('2026-11-08 13:00:00'); // 3 hours late

        $this->actingAs($this->user('Manager'));
        $page = Livewire::test(EditBooking::class, ['record' => $b->id])->mountAction('receive');
        $page->assertActionDataSet(['charges.late' => 300.0]); // suggested from the rule, staff can change it

        $page->setActionData([
            'returned_at' => '2026-11-08 13:00', 'return_km' => 11000, 'return_fuel' => 'half', 'return_condition' => 'minor',
            'charges' => ['late' => 300, 'extra_km' => 500, 'fuel' => 150, 'salik' => 40],
            'payment_amount' => 0, 'deduct_from_deposit' => true, 'refund_deposit' => true, 'refund_method' => 'card',
            'after_return' => 'cleaning', 'return_notes' => 'Needs vacuum',
        ])->callMountedAction()->assertHasNoActionErrors();

        $b->refresh();
        $this->assertSame(BookingStatus::Completed, $b->status);
        $this->assertSame('990.00', $b->extra_charges);
        $this->assertSame('cleaning', $b->inspection_result);
        // owed: 2000 rental balance + 990 charges = 2990; deposit 2000 used; 990 still to collect; nothing left to refund
        $this->assertSame('990.00', $b->balance);
        $this->assertSame(0.0, $b->depositHeld());
        $this->assertSame(Readiness::Cleaning, $b->bookable->fresh()->readiness);
        $this->assertSame(CarStatus::Available, $b->bookable->fresh()->status, 'bookable again, but not ready to hand over');
        $this->assertSame('completed', $b->lead->fresh()->stage);
    }

    public function test_damaged_car_goes_to_maintenance_and_mark_ready_frees_it(): void
    {
        $b = $this->confirmed();
        $this->deliver($b);
        BookingFlow::receive($b->fresh(), ['returned_at' => now(), 'return_km' => 10500, 'return_condition' => 'damaged', 'after_return' => 'maintenance', 'return_notes' => 'Front bumper']);

        $car = $b->bookable->fresh();
        $this->assertSame(CarStatus::Maintenance, $car->status);
        $this->assertSame(Readiness::Inspection, $car->readiness);

        Livewire::test(ListCars::class)->callTableAction('markReady', $car, data: ['note' => 'Repaired'])->assertHasNoTableActionErrors();
        $car->refresh();
        $this->assertSame(Readiness::Ready, $car->readiness);
        $this->assertSame(CarStatus::Available, $car->status);
    }

    public function test_deposit_refund_is_not_a_cost_and_deposit_is_not_revenue(): void
    {
        $b = $this->confirmed();
        $this->deliver($b, ['payment_amount' => 3000]);
        BookingFlow::receive($b->fresh(), ['returned_at' => now(), 'return_km' => 10500, 'after_return' => 'ready', 'refund_deposit' => true, 'refund_method' => 'card']);

        $totals = Transaction::totals(Transaction::query());
        $this->assertSame(3000.0, $totals['received'], 'only the rental counts as revenue');
        $this->assertSame(0.0, $totals['expenses'], 'deposit refund is not an expense');
        $this->assertSame(0.0, $totals['outstanding']);
        $this->assertSame(PaymentStatus::Paid, $b->fresh()->payment_status);
    }

    public function test_double_click_does_not_record_the_payment_twice(): void
    {
        $b = $this->confirmed();
        $b->recordPayment(500, 'cash');
        $b->recordPayment(500, 'cash');
        $this->assertSame(1, $b->transactions()->where('is_balance', false)->count());
        $this->assertSame('500.00', $b->fresh()->paid_amount);

        Carbon::setTestNow(now()->addMinute());
        $b->recordPayment(500, 'cash');
        $this->assertSame('1000.00', $b->fresh()->paid_amount, 'a real second payment later is fine');
    }

    public function test_marking_the_balance_row_paid_updates_the_booking(): void
    {
        $b = $this->confirmed();
        $row = Transaction::where('booking_id', $b->id)->where('is_balance', true)->first();
        $row->update(['status' => PaymentStatus::Paid]);

        $b->refresh();
        $this->assertSame('0.00', $b->balance);
        $this->assertSame(PaymentStatus::Paid, $b->payment_status);
        $this->assertSame(0, Transaction::where('booking_id', $b->id)->where('is_balance', true)->count());
        $this->assertSame(1, Transaction::where('booking_id', $b->id)->where('type', TransactionType::Income)->count(), 'no duplicate payment row');
    }

    public function test_partial_payment_on_balance_row_is_split_off(): void
    {
        $b = $this->confirmed();
        $row = Transaction::where('booking_id', $b->id)->where('is_balance', true)->first();
        $row->update(['status' => PaymentStatus::PartiallyPaid, 'amount_paid' => 1200]);

        $b->refresh();
        $this->assertSame('1200.00', $b->paid_amount);
        $this->assertSame('1800.00', $b->balance);
        $this->assertSame(PaymentStatus::PartiallyPaid, $b->payment_status);
    }

    public function test_cancelled_booking_keeps_payments_and_deposit_can_be_refunded_separately(): void
    {
        $b = $this->confirmed();
        $b->recordPayment(1000, 'card');
        $b->recordPayment(2000, 'card', 'deposit', 'Security deposit');
        $b->update(['status' => BookingStatus::Cancelled, 'cancel_reason' => 'Changed plans']);

        $b->refresh();
        $this->assertSame('1000.00', $b->paid_amount, 'payment history kept; no automatic refund');
        $this->assertSame('0.00', $b->balance, 'nothing more to collect');

        $this->actingAs($this->user('Finance'));
        Livewire::test(EditBooking::class, ['record' => $b->id])
            ->assertActionVisible('refundDeposit')
            ->callAction('refundDeposit', data: ['amount' => 2000, 'method' => 'card'])
            ->assertHasNoActionErrors();
        $this->assertSame(0.0, $b->fresh()->depositHeld());
    }

    public function test_operations_cannot_refund_and_sales_cannot_hand_over(): void
    {
        $b = $this->confirmed();
        $this->actingAs($this->user('Sales'));
        Livewire::test(EditBooking::class, ['record' => $b->id])->assertActionHidden('deliver');
    }
}
