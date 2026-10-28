<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Filament\Pages\ProfitReports;
use App\Filament\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\Booking;
use App\Models\Transaction;
use App\Support\Accounts\BalanceHistory;
use App\Support\Accounts\ProfitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 8: expense controls, corrections, profit report and balance history. */
class AccountsTest extends TestCase
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

    protected function expense(array $attrs = []): Transaction
    {
        return Transaction::create($attrs + ['type' => TransactionType::Expense, 'category' => 'fuel', 'amount' => 100, 'date' => today(), 'status' => PaymentStatus::Paid, 'description' => 'Fuel']);
    }

    protected function completed(Booking $b, string $returnedAt): Booking
    {
        $b->forceFill(['status' => BookingStatus::Completed, 'returned_at' => Carbon::parse($returnedAt)])->save();

        return $b->fresh();
    }

    public function test_expenses_from_staff_wait_for_approval_and_only_count_once_approved(): void
    {
        $this->actingAs($this->user('Operations'));
        $t = $this->expense();
        $this->assertSame('pending', $t->approval_status);
        $this->assertFalse($t->counts());
        $this->assertSame(0.0, Transaction::totals(Transaction::query())['expenses']);

        $this->actingAs($this->user('Finance'));
        $own = $this->expense(['amount' => 40]);
        $this->assertNull($own->approval_status, 'finance is never held');
        $t->approve();
        $this->assertSame('approved', $t->fresh()->approval_status);
        $this->assertSame(140.0, Transaction::totals(Transaction::query())['expenses']);

        $this->actingAs($this->user('Operations'));
        $rejected = $this->expense(['amount' => 70]);
        $this->actingAs($this->user('Finance'));
        $rejected->reject('Not ours');
        $this->assertSame('rejected', $rejected->fresh()->approval_status);
        $this->assertSame(140.0, Transaction::totals(Transaction::query())['expenses']);

        // Switch off: staff expenses count straight away.
        $this->settings(['expense_approval_required' => false]);
        $this->actingAs($this->user('Operations'));
        $this->assertNull($this->expense()->approval_status);
    }

    public function test_reversed_rows_and_estimates_leave_totals_and_the_booking_balance(): void
    {
        $this->actingAs($this->user('Finance'));
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $due = $b->amountDue();
        $pay = $b->recordPayment(500, 'card');
        $this->assertEqualsWithDelta($due - 500, (float) $b->fresh()->balance, 0.01);

        $pay->reverse('Card payment failed');
        $b->refresh();
        $this->assertEqualsWithDelta($due, (float) $b->balance, 0.01, 'reversed payment no longer reduces the balance');
        $this->assertEqualsWithDelta(0.0, (float) $b->paid_amount, 0.01);
        $this->assertNotNull(Transaction::find($pay->id), 'the row is kept');

        try {
            $b->transactions()->where('is_balance', true)->first()->reverse('x');
            $this->fail('balance row cannot be reversed');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expense(['amount' => 300, 'is_estimate' => true, 'booking_id' => $b->id]);
        $this->assertSame(0.0, Transaction::totals(Transaction::query())['expenses'], 'estimates are not costs');
    }

    public function test_the_same_payment_twice_in_30_seconds_is_refused(): void
    {
        $this->actingAs($this->user('Finance'));
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $row = ['type' => TransactionType::Income, 'booking_id' => $b->id, 'category' => 'car_rental', 'amount' => 250, 'date' => today(), 'status' => PaymentStatus::Paid, 'method' => 'cash', 'description' => 'Cash'];
        Transaction::create($row);
        try {
            Transaction::create($row);
            $this->fail('duplicate payment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.amount', $e->errors());
        }
        Carbon::setTestNow(now()->addMinute());
        Transaction::create($row);
        $this->assertSame(2, $b->transactions()->where('is_balance', false)->count());
    }

    public function test_paid_rows_cannot_be_deleted_from_the_list(): void
    {
        $this->actingAs($this->user('Finance'));
        $paid = $this->expense();
        $pending = $this->expense(['status' => PaymentStatus::Pending, 'amount' => 20]);

        Livewire::test(ListTransactions::class)
            ->assertTableActionHidden('delete', $paid)
            ->assertTableActionVisible('delete', $pending)
            ->assertTableActionVisible('reverse', $paid);
    }

    public function test_agent_adds_a_booking_expense_that_waits_for_finance(): void
    {
        $sales = $this->user('Sales');
        $this->actingAs($sales);
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00', ['assigned_to' => $sales->id]);

        Livewire::test(EditBooking::class, ['record' => $b->getRouteKey()])
            ->callAction('addExpense', ['category' => 'fuel', 'amount' => 80, 'date' => '2026-11-02', 'vendor' => 'ENOC'])
            ->assertHasNoActionErrors();

        $t = $b->transactions()->where('type', TransactionType::Expense)->first();
        $this->assertSame('pending', $t->approval_status);
        $this->assertSame('ENOC', $t->vendor);
        $this->assertSame([], ProfitReport::directCosts([$b->id])->all(), 'not counted until approved');
    }

    public function test_profit_report_earned_contribution_and_net(): void
    {
        $this->actingAs($this->user('Finance'));
        $car = $this->car();
        $done = $this->booking($car, '2026-11-03 10:00', '2026-11-05 10:00');
        $done->recordPayment((float) $done->total, 'card');
        $done->recordPayment(1000, 'card', 'deposit');                       // deposit: never revenue
        $done = $this->completed($done, '2026-11-05 10:00');
        $later = $this->booking($car, '2026-12-03 10:00', '2026-12-05 10:00'); // not completed: not earned

        $this->expense(['booking_id' => $done->id, 'amount' => 150]);           // direct cost
        $this->expense(['category' => 'rent', 'amount' => 400, 'date' => '2026-11-15']); // overhead
        $this->expense(['category' => 'deposit_refund', 'amount' => 1000, 'booking_id' => $done->id]); // not a cost
        $this->expense(['amount' => 999, 'is_estimate' => true]);                 // not a cost

        $s = ProfitReport::forRange(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59:59'))->summary();
        $this->assertSame(1, $s['earned_count']);
        $this->assertEqualsWithDelta((float) $done->total, $s['earned'], 0.01);
        $this->assertEqualsWithDelta((float) $done->total, $s['received'], 0.01, 'deposit not in cash received');
        $this->assertEqualsWithDelta(150, $s['direct_costs'], 0.01);
        $this->assertEqualsWithDelta((float) $done->total - 150, $s['contribution'], 0.01);
        $this->assertEqualsWithDelta(550, $s['expenses'], 0.01);
        $this->assertEqualsWithDelta((float) $done->total - 550, $s['net'], 0.01);
        $this->assertEqualsWithDelta(999, $s['estimates'], 0.01);
        $this->assertEqualsWithDelta(1000, $s['deposit_refunds'], 0.01);

        $rows = ProfitReport::forRange(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59:59'))->rows('car');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertNotContains($later->id, $rows[0]['bookings']);

        // The page itself renders for finance and is closed to sales.
        Livewire::test(ProfitReports::class)->assertOk()->assertSee('Earned revenue')->set('by', 'month')->assertSee('Nov 2026')->set('by', 'category')->assertSee('Office & rent');
        $this->actingAs($this->user('Sales'));
        $this->get(ProfitReports::getUrl())->assertForbidden();
    }

    public function test_earned_revenue_leaves_out_vat_and_counts_money_kept_on_cancellations(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->settings(['vat_enabled' => true, 'vat_rate' => 5]);
        $b = $this->booking($this->car(), '2026-11-03 10:00', '2026-11-05 10:00', ['tax_rate' => 5]);
        $this->assertGreaterThan(0, (float) $b->tax_amount);
        $b = $this->completed($b, '2026-11-05 10:00');
        $this->assertEqualsWithDelta((float) $b->total - (float) $b->tax_amount, ProfitReport::earnedFor($b), 0.01);

        $c = $this->booking($this->car(), '2026-11-06 10:00', '2026-11-07 10:00');
        $c->recordPayment(200, 'card');
        $c->forceFill(['status' => BookingStatus::Cancelled])->save();
        $this->assertEqualsWithDelta(200, ProfitReport::earnedFor($c->fresh()), 0.01);
    }

    public function test_balance_history_explains_price_changes_and_payments(): void
    {
        $this->actingAs($this->user('Manager'));
        $b = $this->booking($this->car(), '2026-11-10 10:00', '2026-11-12 10:00');
        $first = (float) $b->total;
        $b->update(['discount' => 100, 'adjustment_reason' => 'Loyal customer']);
        $b->refresh();
        $this->assertEqualsWithDelta($first - 100, (float) $b->total, 0.01);
        $pay = $b->recordPayment(300, 'cash');
        $pay->reverse('Typed twice');
        $b->recordPayment(300, 'card');

        $events = BalanceHistory::for($b->fresh());
        $whats = array_column($events, 'what');
        $this->assertSame('Booking created', $whats[0]);
        $this->assertEqualsWithDelta($first, $events[0]['due'], 0.01);
        $price = collect($events)->firstWhere(fn ($e) => str_starts_with($e['what'], 'Price changed'));
        $this->assertEqualsWithDelta(-100, $price['due'], 0.01);
        $this->assertSame('Loyal customer', $price['note']);
        $payments = collect($events)->filter(fn ($e) => str_starts_with($e['what'], 'Payment received'))->values();
        $this->assertCount(2, $payments);
        $this->assertSame(0.0, $payments[0]['paid'], 'reversed payment shown at zero');
        $this->assertStringContainsString('Typed twice', $payments[0]['note']);
        $this->assertSame(300.0, $payments[1]['paid']);
        $this->assertStringContainsString('Balance AED '.number_format((float) $b->fresh()->balance, 2), BalanceHistory::html($b->fresh()));
    }
}
