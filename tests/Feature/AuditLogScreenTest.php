<?php

namespace Tests\Feature;

use App\Filament\Resources\HistoryResource\Pages\ListHistory;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 19 Nov (Bilal, item 6): Audit log server error fixed, filters, search, details, coverage. */
class AuditLogScreenTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    public function test_one_old_line_with_an_unknown_record_type_no_longer_breaks_the_screen(): void
    {
        $this->seedRoles();
        $this->actingAs($this->user('Admin'));
        foreach (array_keys(Relation::morphMap()) as $alias) {
            AuditLog::create(['event' => 'updated', 'auditable_type' => $alias, 'auditable_id' => 1, 'old_values' => ['a' => ['x' => 1]], 'new_values' => ['a' => ['x' => 2]]]);
        }
        $odd = [
            AuditLog::create(['event' => 'updated', 'auditable_type' => 'App\\Models\\RemovedLongAgo', 'auditable_id' => 3, 'new_values' => ['status' => 'x']]),
            AuditLog::create(['event' => 'deleted', 'auditable_type' => 'old_alias', 'auditable_id' => 4]),
        ];

        // The live error (17–19 Nov): a line about an invoice linked to an invoice edit page that does not exist.
        $b = $this->booking($this->car(), '2026-12-20 10:00', '2026-12-22 10:00', ['customer_id' => $this->customer()->id]);
        $invoice = \App\Models\Invoice::issueFor($b->fresh());
        $this->assertTrue(AuditLog::where('auditable_type', 'invoice')->where('auditable_id', $invoice->id)->exists());
        $this->assertNull(\App\Support\Audit\AuditView::recordUrl(AuditLog::where('auditable_type', 'invoice')->first()));

        $this->get('/crm/history')->assertOk();
        Livewire::test(\App\Filament\Resources\HistoryResource\Widgets\AuditOverview::class)->assertSee('Actions today');
        Livewire::test(ListHistory::class)->assertCanSeeTableRecords($odd)->assertSee('(removed)');
        foreach (['changes', 'deletions', 'signins', 'money', 'settings', 'problems'] as $tab) {
            $this->get('/crm/history?activeTab='.$tab)->assertOk();
        }
    }

    public function test_who_module_action_date_filters_search_and_details(): void
    {
        $this->seedRoles();
        $admin = $this->user('Admin');
        $agent = $this->user('Agent', ['name' => 'Sara Agent']);
        $this->actingAs($agent);
        $customer = $this->customer(['name' => 'Omar Customer']);
        $b = $this->booking($this->car(), '2026-12-20 10:00', '2026-12-22 10:00', ['customer_id' => $customer->id]);
        $b->update(['notes' => 'VIP']);
        $this->actingAs($admin);

        $agentLines = AuditLog::where('user_id', $agent->id)->get();
        $this->assertTrue($agentLines->contains('auditable_type', 'booking'), 'the agent\'s booking is recorded with their name');
        $update = $agentLines->firstWhere(fn ($l) => $l->auditable_type === 'booking' && $l->event === 'updated');
        $this->assertSame('Agent', $update->role);

        Livewire::test(ListHistory::class)
            ->filterTable('user_id', [$agent->id])->assertCanSeeTableRecords([$update])
            ->resetTableFilters()
            ->filterTable('module', ['Bookings'])->assertCanSeeTableRecords([$update])
            ->resetTableFilters()
            ->filterTable('event', ['updated'])->assertCanSeeTableRecords([$update])
            ->resetTableFilters()
            ->filterTable('created_at', ['period' => 'today'])->assertCanSeeTableRecords([$update])
            ->resetTableFilters()
            ->searchTable('Sara')->assertCanSeeTableRecords([$update])
            ->searchTable((string) $b->id)->assertCanSeeTableRecords([$update])
            ->searchTable('nobody-has-this')->assertCanNotSeeTableRecords([$update])
            ->searchTable('')
            ->mountTableAction('details', $update)->assertSee('VIP')->assertSee('Sara Agent');
    }

    public function test_settings_changes_and_contract_drivers_are_recorded_and_agents_cannot_see_or_change_logs(): void
    {
        $this->seedRoles();
        $admin = $this->user('Admin');
        $this->actingAs($admin);
        Setting::write(['contract_number_prefix' => 'RX']);
        $line = AuditLog::where('event', 'settings')->latest('id')->first();
        $this->assertNotNull($line);
        $this->assertSame('RX', $line->new_values['contract_number_prefix']);
        $this->assertSame($admin->id, $line->user_id);

        $b = $this->booking($this->car(), '2026-12-20 10:00', '2026-12-22 10:00', ['customer_id' => $this->customer()->id]);
        $c = \App\Support\Contracts\Contracts::make($b);
        $this->assertTrue(AuditLog::where('auditable_type', 'contract_driver')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', 'rental_contract')->exists());

        $this->flushSession();
        $this->actingAs($this->user('Agent'));
        $this->get('/crm/history')->assertForbidden();
        $this->expectException(\LogicException::class);
        $line->update(['note' => 'changed']);
    }
}
