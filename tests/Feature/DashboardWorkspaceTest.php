<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\DriveMode;
use App\Enums\Pipeline;
use App\Enums\QuotationStatus;
use App\Enums\ServiceType;
use App\Filament\Widgets\BusinessBoard;
use App\Filament\Widgets\MyWork;
use App\Models\Lead;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 28 Oct brief, Priority 1: dashboard numbers open their records; agents get their own work list. */
class DashboardWorkspaceTest extends TestCase
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

    protected function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + ['name' => 'Sara Khan', 'phone' => '0501112233', 'pipeline' => Pipeline::Car, 'service_type' => ServiceType::Car]);
    }

    public function test_agent_sees_only_their_own_next_steps(): void
    {
        $manager = $this->user('Manager');
        $agent = $this->user('Agent');
        $other = $this->user('Agent');

        $this->actingAs($manager);
        $mine = $this->lead(['name' => 'Mine Lead', 'assigned_to' => $agent->id]);
        $this->lead(['name' => 'Other Lead', 'assigned_to' => $other->id]);
        $car = $this->car(['name' => 'Bentley Bentayga']);
        $q = Quotation::create(['lead_id' => $mine->id, 'service_type' => ServiceType::Car, 'bookable_id' => $car->id, 'drive_mode' => DriveMode::SelfDrive,
            'start_at' => '2026-11-10 10:00', 'end_at' => '2026-11-12 10:00', 'valid_until' => '2026-11-06']);
        $q->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        $b = $this->booking($car, '2026-11-04 10:00', '2026-11-05 10:00', ['status' => BookingStatus::PendingPayment, 'assigned_to' => $agent->id]);

        $this->actingAs($agent);
        $this->assertTrue(MyWork::canView());
        Livewire::test(MyWork::class)
            ->assertSee('New enquiries for you')
            ->assertSee('Mine Lead')
            ->assertDontSee('Other Lead')
            ->assertSee('Waiting for the customer')
            ->assertSee($q->number)
            ->assertSee('Waiting for payment')
            ->assertSee($b->reference);

        // The board counts only the agent's own records and hides money.
        Livewire::test(BusinessBoard::class)
            ->assertSee('Waiting for payment')
            ->assertSee('activeTab=waiting', false)
            ->assertDontSee('Money this month');
    }

    public function test_finance_sees_money_and_every_number_links_to_a_list(): void
    {
        $this->actingAs($this->user('Finance'));
        $this->assertFalse(MyWork::canView(), 'finance without leads gets no sales work list');
        $html = Livewire::test(BusinessBoard::class)->assertSee('Money this month')->assertSee('Late returns')->html();
        preg_match_all('/class="rp-glance-row[^"]*"/', $html, $rows);
        preg_match_all('/<a href="http[^"]+" class="rp-glance-row/', $html, $links);
        $this->assertNotEmpty($rows[0]);
        $this->assertCount(count($rows[0]), $links[0], 'every row is a link');
    }

    public function test_late_and_waiting_tabs_list_the_right_bookings(): void
    {
        $this->actingAs($this->user('Manager'));
        $car = $this->car();
        $late = $this->booking($car, '2026-10-30 10:00', '2026-11-01 10:00');
        $late->forceFill(['status' => BookingStatus::InProgress])->save();
        $waiting = $this->booking($this->car(), '2026-11-08 10:00', '2026-11-09 10:00', ['status' => BookingStatus::PendingPayment]);

        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class, ['activeTab' => 'late'])
            ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$waiting]);
        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class, ['activeTab' => 'waiting'])
            ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$late]);
    }

    public function test_driver_dashboard_shows_neither(): void
    {
        $this->actingAs($this->user('Driver'));
        $this->assertFalse(MyWork::canView());
        $this->assertFalse(BusinessBoard::canView());
    }
}
